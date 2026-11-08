<?php

namespace App\Payments;

use RuntimeException;

/**
 * The payment did not go through. Part of the PaymentGateway contract.
 * Rendered as 402 JSON in bootstrap/app.php; this class itself knows nothing about HTTP.
 */
final class PaymentFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason, // machine-readable code, e.g. card_declined, gateway_error
        string $message = 'The payment was declined.',
    ) {
        parent::__construct($message);
    }
}
