<?php

use App\Enums\IdempotencyStatus;
use App\Enums\UserRole;
use App\Models\IdempotencyKey;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

function claimKey(User $user, string $key): IdempotencyKey
{
    return IdempotencyKey::create([
        'user_id' => $user->id,
        'key' => $key,
        'request_hash' => str_repeat('a', 64),
        'status' => IdempotencyStatus::Processing,
    ]);
}

it('rejects a second claim of the same idempotency key for the same user', function () {
    $user = User::factory()->create();
    claimKey($user, 'key-1');

    claimKey($user, 'key-1');
})->throws(UniqueConstraintViolationException::class);

it('allows two different users to use the same idempotency key', function () {
    claimKey(User::factory()->create(), 'key-1');
    claimKey(User::factory()->create(), 'key-1');

    expect(IdempotencyKey::count())->toBe(2);
});

it('never lets mass assignment set the role', function () {
    $user = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'secret-password',
        'role' => 'admin',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Member);
});

it('stores the password as a bcrypt hash, not plain text', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    expect($user->password)->not->toBe('secret-password')
        ->and(password_get_info($user->password)['algoName'])->toBe('bcrypt');
});

it('seeds an admin, a member and one inactive plan', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('role', UserRole::Admin)->count())->toBe(1)
        ->and(User::where('role', UserRole::Member)->count())->toBe(1)
        ->and(Plan::where('is_active', false)->count())->toBe(1);
});
