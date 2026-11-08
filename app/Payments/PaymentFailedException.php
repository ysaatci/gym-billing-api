<?php

namespace App\Payments;

use RuntimeException;

/** Part of the PaymentGateway contract. Mapped to 402 in bootstrap/app.php. */
final class PaymentFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason, // e.g. card_declined, gateway_error
        string $message = 'The payment was declined.',
    ) {
        parent::__construct($message);
    }
}
