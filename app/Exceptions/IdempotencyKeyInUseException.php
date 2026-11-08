<?php

namespace App\Exceptions;

use RuntimeException;

/** The first request with this key hasn't finished. Mapped to 409. */
final class IdempotencyKeyInUseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still being processed. Retry later.');
    }
}
