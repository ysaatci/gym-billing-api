<?php

namespace App\Services;

use App\Enums\IdempotencyStatus;
use App\Exceptions\IdempotencyKeyInUseException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Models\IdempotencyKey;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Everything about Idempotency-Key rows, so SubscriptionService only orchestrates (D12).
 * The actual guarantee comes from the database: unique(user_id, key) on idempotency_keys.
 */
final class IdempotencyStore
{
    /**
     * Claim the key for a new request. Must run inside the transaction that created
     * $subscription: if the key is already taken this insert fails, and the whole
     * transaction (subscription and invoice included) rolls back.
     *
     * Deliberately "insert and let the DB refuse" instead of "check, then insert":
     * two concurrent requests could both pass a check, but only one insert can win (D4).
     *
     * @throws UniqueConstraintViolationException when the key was already claimed
     */
    public function claim(User $user, string $key, string $requestHash, Subscription $subscription): IdempotencyKey
    {
        return IdempotencyKey::create([
            'user_id' => $user->id,
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => IdempotencyStatus::Processing,
            'subscription_id' => $subscription->id,
        ]);
    }

    /**
     * For a request whose key is already claimed: load the claim and check it can be replayed.
     *
     * @throws IdempotencyKeyMismatchException same key, different request body (422, D5)
     * @throws IdempotencyKeyInUseException the first request is still running (409)
     */
    public function findReplayable(User $user, string $key, string $requestHash): IdempotencyKey
    {
        // select * from idempotency_keys where user_id = ? and key = ? limit 1
        $claim = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->firstOrFail();

        // The key itself is client-chosen text, so we log the row ID, not the key.
        if ($claim->request_hash !== $requestHash) {
            Log::channel('billing')->warning('idempotency.key_mismatch', ['user_id' => $user->id, 'idempotency_key_id' => $claim->id]);

            throw new IdempotencyKeyMismatchException;
        }

        if ($claim->status === IdempotencyStatus::Processing) {
            Log::channel('billing')->warning('idempotency.key_in_use', ['user_id' => $user->id, 'idempotency_key_id' => $claim->id]);

            throw new IdempotencyKeyInUseException;
        }

        return $claim;
    }

    public function complete(IdempotencyKey $claim): void
    {
        $claim->update(['status' => IdempotencyStatus::Completed]);
    }
}
