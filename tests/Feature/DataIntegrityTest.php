<?php

use App\Enums\IdempotencyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\IdempotencyKey;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function insertIdempotencyKey(User $user, string $key): IdempotencyKey
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
    insertIdempotencyKey($user, 'key-1');

    insertIdempotencyKey($user, 'key-1');
})->throws(UniqueConstraintViolationException::class);

it('allows two different users to use the same idempotency key', function () {
    insertIdempotencyKey(User::factory()->create(), 'key-1');
    insertIdempotencyKey(User::factory()->create(), 'key-1');

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

it('refuses to change the status of a subscription that is no longer pending', function () {
    $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Failed]);

    $subscription->markActive();
})->throws(LogicException::class, 'expected pending');

it('refuses to pay an invoice that is no longer open', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Void]);

    $invoice->markPaid('fake_ch_123');
})->throws(LogicException::class, 'expected open');
