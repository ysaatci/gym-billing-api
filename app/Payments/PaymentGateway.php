<?php

namespace App\Payments;

/**
 * Anything that can charge money. The service depends only on this interface;
 * AppServiceProvider decides which implementation it gets.
 *
 * Contract for every implementation:
 * - returns a ChargeResult when the money was taken;
 * - throws PaymentFailedException for ANY failure, including SDK errors and timeouts,
 *   so nothing provider-specific leaks out;
 * - sets connect and read timeouts on its HTTP calls (e.g. 2s / 10s), so a slow
 *   provider can't hold a request open until PHP's execution limit.
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
