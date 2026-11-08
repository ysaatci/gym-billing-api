<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (user, Idempotency-Key header). Stores the outcome of the first
 * request so a retry gets the exact same response instead of a second charge (D2).
 */
#[Fillable(['user_id', 'key', 'request_hash', 'status', 'response_code', 'response_body', 'subscription_id'])]
class IdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_code' => 'integer',
            'response_body' => 'array', // JSON column <-> PHP array
        ];
    }
}
