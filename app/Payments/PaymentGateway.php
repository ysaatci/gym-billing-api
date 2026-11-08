<?php

namespace App\Payments;

/**
 * Anything that can charge money. The service depends only on this interface;
 * AppServiceProvider decides which implementation it gets.
 *
 * Contract for every implementation:
 * - returns a ChargeResult when the money was taken;
 * - throws PaymentFailedException for ANY failure, including SDK errors and timeouts,
 *   so nothing provider-specific leaks out.
 */
interface PaymentGateway
{
    /**
     * @param  string  $idempotencyKey  forwarded to the provider, so the same charge is never made twice
     *
     * @throws PaymentFailedException
     */
    public function charge(int $amountCents, string $currency, string $idempotencyKey): ChargeResult;
}
