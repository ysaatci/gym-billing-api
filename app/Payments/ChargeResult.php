<?php

namespace App\Payments;

final readonly class ChargeResult
{
    public function __construct(
        public string $reference, // the provider's charge ID
    ) {}
}
