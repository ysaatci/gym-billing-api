<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The key was already used for a DIFFERENT request body. Almost always a client bug,
 * so we refuse instead of silently replaying the wrong result (D5). Rendered as 422.
 */
final class IdempotencyKeyMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different request.');
    }
}
