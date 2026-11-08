<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (user, Idempotency-Key). Points at the subscription the first request
 * created, so a retry gets the same answer instead of a second charge.
 */
#[Fillable(['user_id', 'key', 'request_hash', 'status', 'subscription_id'])]
class IdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
