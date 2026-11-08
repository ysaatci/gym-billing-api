<?php

namespace App\Exceptions;

use RuntimeException;

/** The key was already used for a different request body (a client bug). Mapped to 422. */
final class IdempotencyKeyMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different request.');
    }
}
