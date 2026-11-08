<?php

namespace App\Payments;

/**
 * Strategy interface for "something that can charge money". SubscriptionService depends
 * only on this, and the container decides which implementation it gets (AppServiceProvider).
 * Today: FakePaymentGateway. Later: a StripeGateway / AdyenGateway that adapts the
 * provider's SDK to this contract, with no change to the service (open/closed).
 *
 * Contract every implementation must keep (so they are interchangeable):
 * - returns a ChargeResult when the money was taken;
 * - throws PaymentFailedException for ANY failure: a decline, but also SDK errors and
 *   timeouts, translated at this boundary. Nothing provider-specific leaks out.
 */
interface PaymentGateway
{
    /**
     * @param  int  $amountCents  amount in minor units (D8)
     * @param  string  $currency  ISO 4217 code
     * @param  string  $idempotencyKey  forwarded to the provider, so a repeated call for
     *                                  the same invoice can never be charged twice (D7)
     *
     * @throws PaymentFailedException
     */
    public function charge(int $amountCents, string $currency, string $idempotencyKey): ChargeResult;
}
