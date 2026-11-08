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
 * Idempotency-Key bookkeeping. The guarantee itself comes from the database:
 * unique(user_id, key) on idempotency_keys.
 */
final class IdempotencyStore
{
    /**
     * Insert and let the database refuse duplicates, instead of "check, then insert":
     * two concurrent requests could both pass a check, but only one insert can win.
     * Runs inside the caller's transaction, so a refused claim rolls back everything with it.
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
     * @throws IdempotencyKeyMismatchException same key, different request body
     * @throws IdempotencyKeyInUseException the first request is still running
     */
    public function findReplayable(User $user, string $key, string $requestHash): IdempotencyKey
    {
        $claim = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->firstOrFail();

        // We log the row ID, not the key: the key is client-chosen text.
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
