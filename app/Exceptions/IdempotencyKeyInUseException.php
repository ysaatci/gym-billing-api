<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The first request with this key hasn't finished yet. Rendered as 409 (bootstrap/app.php);
 * the client should retry the same request a bit later.
 */
final class IdempotencyKeyInUseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still being processed. Retry later.');
    }
}
