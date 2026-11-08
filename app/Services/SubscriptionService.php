<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\IdempotencyKeyInUseException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Models\IdempotencyKey;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\ChargeResult;
use App\Payments\PaymentFailedException;
use App\Payments\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The main use case: subscribe a member to a plan and pay the first invoice, exactly once
 * per Idempotency-Key. Knows nothing about HTTP; the controller and exception handler do that.
 *
 *   phase A  (transaction)  subscription pending + invoice open + key claimed   -> commit
 *   gateway  (no transaction) charge the invoice
 *   phase B  (transaction)  active + paid, or failed + void; key completed      -> commit
 */
final class SubscriptionService
{
    // Both injected by the container. PaymentGateway is an interface; which class we get
    // is decided in AppServiceProvider (Strategy + dependency inversion).
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly IdempotencyStore $idempotency,
    ) {}

    /**
     * @throws PaymentFailedException declined, also when replaying a declined request (402)
     * @throws IdempotencyKeyInUseException first request with this key still running (409)
     * @throws IdempotencyKeyMismatchException key reused with a different body (422)
     */
    public function subscribe(User $user, Plan $plan, string $idempotencyKey, string $requestHash): Subscription
    {
        try {
            [$subscription, $claim] = $this->openSubscription($user, $plan, $idempotencyKey, $requestHash);
        } catch (UniqueConstraintViolationException) {
            // The key is already claimed: a retry, or a concurrent duplicate that lost the race (D4).
            // Nothing was written by this request (phase A rolled back) and nothing gets charged.
            return $this->replay($user, $idempotencyKey, $requestHash);
        }

        $this->payFirstInvoice($subscription, $claim);

        return $subscription;
    }

    /**
     * Phase A. Committed BEFORE the gateway call, so no transaction (and no row locks)
     * is held open while we wait on the network (D1).
     *
     * @return array{Subscription, IdempotencyKey}
     */
    private function openSubscription(User $user, Plan $plan, string $idempotencyKey, string $requestHash): array
    {
        // DB::transaction: BEGIN, run the closure, COMMIT; ROLLBACK and rethrow if it throws.
        return DB::transaction(function () use ($user, $plan, $idempotencyKey, $requestHash) {
            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Pending,
            ]);

            // Copy the price onto the invoice: a later plan price change must not
            // change what this customer owes (D8).
            $subscription->invoice()->create([
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'status' => InvoiceStatus::Open,
            ]);

            // Last, so the claim can point at the subscription. If the key is taken,
            // this throws and the two inserts above are rolled back with it.
            $claim = $this->idempotency->claim($user, $idempotencyKey, $requestHash, $subscription);

            return [$subscription, $claim];
        });
    }

    /**
     * The gateway call, outside any transaction (D1), then phase B records the outcome.
     * Known gap: if the process dies between the charge and phase B, the key stays
     * "processing" and the subscription "pending"; a webhook or reconciliation job would
     * have to settle it (D6, README "Known limitations").
     */
    private function payFirstInvoice(Subscription $subscription, IdempotencyKey $claim): void
    {
        $invoice = $subscription->invoice;

        try {
            $charge = $this->gateway->charge(
                $invoice->amount_cents,
                $invoice->currency,
                // The provider gets one key per invoice: stable if we ever retry the charge,
                // and unique across users (client keys are only unique per user) (D7).
                "invoice-{$invoice->id}",
            );
        } catch (PaymentFailedException $e) {
            $this->recordDecline($subscription, $invoice, $claim, $e);

            throw $e; // the exception handler turns it into a 402
        }

        $this->recordPayment($subscription, $invoice, $claim, $charge);
    }

    /** Phase B, success. */
    private function recordPayment(Subscription $subscription, Invoice $invoice, IdempotencyKey $claim, ChargeResult $charge): void
    {
        DB::transaction(function () use ($subscription, $invoice, $claim, $charge) {
            $subscription->markActive();
            $invoice->markPaid($charge->reference);
            $this->idempotency->complete($claim);
        });
    }

    /**
     * Phase B, decline. Stored as a final result: a retry with the same key gets the
     * same 402 and is never charged again (D6).
     */
    private function recordDecline(Subscription $subscription, Invoice $invoice, IdempotencyKey $claim, PaymentFailedException $e): void
    {
        DB::transaction(function () use ($subscription, $invoice, $claim, $e) {
            $subscription->markFailed();
            $invoice->markVoid($e->reason);
            $this->idempotency->complete($claim);
        });
    }

    /**
     * Same answer as the first request, rebuilt from what it stored. The gateway is not called.
     */
    private function replay(User $user, string $idempotencyKey, string $requestHash): Subscription
    {
        $subscription = $this->idempotency->findReplayable($user, $idempotencyKey, $requestHash)->subscription;

        if ($subscription->status === SubscriptionStatus::Failed) {
            throw new PaymentFailedException($subscription->invoice->failure_reason);
        }

        return $subscription;
    }
}
