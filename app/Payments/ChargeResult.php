<?php

namespace App\Payments;

/**
 * What a successful charge gives back. A small immutable value object instead of
 * a raw SDK array, so the rest of the code doesn't depend on any provider's format.
 */
final readonly class ChargeResult
{
    public function __construct(
        public string $reference, // the provider's charge ID, stored on the invoice
    ) {}
}
