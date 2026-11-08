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
use Illuminate\Support\Facades\Log;

/**
 * Subscribe a member to a plan and pay the first invoice, exactly once per Idempotency-Key.
 *
 *   phase A  (transaction)     pending subscription + open invoice + key claimed
 *   gateway  (no transaction)  charge the invoice
 *   phase B  (transaction)     active + paid, or failed + void; key completed
 */
final class SubscriptionService
{
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
            // Key already claimed: a retry, or a concurrent duplicate that lost the race.
            // Phase A rolled back, so this request wrote nothing and charges nothing.
            return $this->replay($user, $idempotencyKey, $requestHash);
        }

        $this->payFirstInvoice($subscription, $claim);

        return $subscription;
    }

    /**
     * Phase A, committed before the gateway call so no transaction is held open
     * while we wait on the network.
     *
     * @return array{Subscription, IdempotencyKey}
     */
    private function openSubscription(User $user, Plan $plan, string $idempotencyKey, string $requestHash): array
    {
        return DB::transaction(function () use ($user, $plan, $idempotencyKey, $requestHash) {
            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Pending,
            ]);

            // The price is copied, so a later plan price change doesn't change what this customer owes.
            $subscription->invoice()->create([
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'status' => InvoiceStatus::Open,
            ]);

            // Last, so the claim can point at the subscription. If the key is taken,
            // this throws and the two inserts above roll back with it.
            $claim = $this->idempotency->claim($user, $idempotencyKey, $requestHash, $subscription);

            return [$subscription, $claim];
        });
    }

    /**
     * Not handled: if the process dies between the charge and phase B, the key stays
     * "processing" and the subscription "pending" until a webhook or reconciliation job
     * settles it (not built yet).
     */
    private function payFirstInvoice(Subscription $subscription, IdempotencyKey $claim): void
    {
        $invoice = $subscription->invoice;

        try {
            $charge = $this->gateway->charge(
                $invoice->amount_cents,
                $invoice->currency,
                // Not the client's key: that one is only unique per user, and would collide
                // at the provider. One key per invoice is also stable for a later retry.
                "invoice-{$invoice->id}",
            );
        } catch (PaymentFailedException $e) {
            $this->recordDecline($subscription, $invoice, $claim, $e);

            Log::channel('billing')->warning('payment.declined', [
                ...$this->logContext($subscription, $invoice),
                'reason' => $e->reason,
            ]);

            throw $e;
        }

        $this->recordPayment($subscription, $invoice, $claim, $charge);

        Log::channel('billing')->info('payment.succeeded', [
            ...$this->logContext($subscription, $invoice),
            'gateway_reference' => $charge->reference,
        ]);
    }

    private function recordPayment(Subscription $subscription, Invoice $invoice, IdempotencyKey $claim, ChargeResult $charge): void
    {
        DB::transaction(function () use ($subscription, $invoice, $claim, $charge) {
            $subscription->markActive();
            $invoice->markPaid($charge->reference);
            $this->idempotency->complete($claim);
        });
    }

    /** A decline is final: a retry with the same key gets the same 402 and is never charged again. */
    private function recordDecline(Subscription $subscription, Invoice $invoice, IdempotencyKey $claim, PaymentFailedException $e): void
    {
        DB::transaction(function () use ($subscription, $invoice, $claim, $e) {
            $subscription->markFailed();
            $invoice->markVoid($e->reason);
            $this->idempotency->complete($claim);
        });
    }

    /** Same answer as the first request, rebuilt from what it stored. The gateway is not called. */
    private function replay(User $user, string $idempotencyKey, string $requestHash): Subscription
    {
        $subscription = $this->idempotency->findReplayable($user, $idempotencyKey, $requestHash)->subscription;

        Log::channel('billing')->info('idempotency.replayed', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'status' => $subscription->status->value,
        ]);

        if ($subscription->status === SubscriptionStatus::Failed) {
            throw new PaymentFailedException($subscription->invoice->failure_reason);
        }

        return $subscription;
    }

    /** IDs and amounts only: never the email or any card data. */
    private function logContext(Subscription $subscription, Invoice $invoice): array
    {
        return [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => $invoice->amount_cents,
            'currency' => $invoice->currency,
        ];
    }
}
