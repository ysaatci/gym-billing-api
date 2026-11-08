<?php

namespace App\Enums;

/**
 * processing: a request with this key is in flight; duplicates get 409.
 * completed:  the response is stored; duplicates get it replayed.
 */
enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
