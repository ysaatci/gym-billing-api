<?php

use App\Enums\IdempotencyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\IdempotencyKey;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;

/** Swap the container binding for a fake we can inspect afterwards. */
function fakeGateway(bool $decline = false): FakePaymentGateway
{
    $fake = new FakePaymentGateway(declineAll: $decline);
    app()->instance(PaymentGateway::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->plan = Plan::factory()->create(['price_cents' => 2999, 'currency' => 'EUR']);
});

function postSubscription(User $user, array $body, ?string $key = 'key-123')
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return test()->actingAs($user)->postJson('/api/subscriptions', $body, $headers);
}

it('creates an active subscription and a paid invoice', function () {
    $gateway = fakeGateway();

    $response = postSubscription($this->member, ['plan_id' => $this->plan->id]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.plan.id', $this->plan->id)
        ->assertJsonPath('data.invoice.amount_cents', 2999)
        ->assertJsonPath('data.invoice.currency', 'EUR')
        ->assertJsonPath('data.invoice.status', 'paid');

    $subscription = Subscription::sole();
    expect($subscription->user_id)->toBe($this->member->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->started_at)->not->toBeNull()
        ->and($subscription->invoice->gateway_reference)->toStartWith('fake_ch_')
        ->and(IdempotencyKey::sole()->status)->toBe(IdempotencyStatus::Completed);

    // Charged once, the invoice amount, with a per-invoice key for the provider.
    expect($gateway->charges)->toBe([[
        'amount_cents' => 2999,
        'currency' => 'EUR',
        'idempotency_key' => "invoice-{$subscription->invoice->id}",
    ]]);
});

it('replays a retried request without charging again', function () {
    $gateway = fakeGateway();

    $first = postSubscription($this->member, ['plan_id' => $this->plan->id])->assertCreated();
    $retry = postSubscription($this->member, ['plan_id' => $this->plan->id])->assertCreated();

    expect($retry->json())->toBe($first->json())
        ->and($gateway->charges)->toHaveCount(1)
        ->and(Subscription::count())->toBe(1)
        ->and(Invoice::count())->toBe(1);
});

it('rejects the same key with a different body (422) and charges nothing new', function () {
    $gateway = fakeGateway();
    $otherPlan = Plan::factory()->create(['name' => 'Premium', 'price_cents' => 4999]);

    postSubscription($this->member, ['plan_id' => $this->plan->id])->assertCreated();

    postSubscription($this->member, ['plan_id' => $otherPlan->id])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request.');

    expect($gateway->charges)->toHaveCount(1)
        ->and(Subscription::count())->toBe(1);
});

it('answers 409 while the first request with the same key is still processing', function () {
    $gateway = fakeGateway();

    // Simulates a concurrent duplicate: the first request has claimed the key (phase A
    // committed) but hasn't finished. SQLite can't run two requests truly in parallel,
    // so this tests the branch, not the race itself (README "Known limitations").
    $inFlight = Subscription::factory()->for($this->member)->for($this->plan)
        ->create(['status' => SubscriptionStatus::Pending, 'started_at' => null]);
    IdempotencyKey::create([
        'user_id' => $this->member->id,
        'key' => 'key-123',
        'request_hash' => hash('sha256', json_encode(['plan_id' => $this->plan->id])),
        'status' => IdempotencyStatus::Processing,
        'subscription_id' => $inFlight->id,
    ]);

    postSubscription($this->member, ['plan_id' => $this->plan->id])->assertStatus(409);

    expect($gateway->charges)->toBeEmpty()
        ->and(Subscription::count())->toBe(1);
});

it('lets two different users use the same key independently', function () {
    $gateway = fakeGateway();

    postSubscription($this->member, ['plan_id' => $this->plan->id])->assertCreated();
    postSubscription(User::factory()->create(), ['plan_id' => $this->plan->id])->assertCreated();

    expect($gateway->charges)->toHaveCount(2)
        ->and(Subscription::count())->toBe(2);
});

it('records a declined payment and returns 402, also on retry', function () {
    $gateway = fakeGateway(decline: true);

    postSubscription($this->member, ['plan_id' => $this->plan->id])
        ->assertStatus(402)
        ->assertExactJson(['message' => 'The payment was declined.', 'reason' => 'card_declined']);

    $subscription = Subscription::sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Failed)
        ->and($subscription->invoice->status)->toBe(InvoiceStatus::Void)
        ->and($subscription->invoice->failure_reason)->toBe('card_declined');

    // Retry with the same key: same answer, no second charge attempt.
    postSubscription($this->member, ['plan_id' => $this->plan->id])
        ->assertStatus(402)
        ->assertExactJson(['message' => 'The payment was declined.', 'reason' => 'card_declined']);

    expect($gateway->charges)->toHaveCount(1);
});

it('keeps the invoice amount when the plan price changes later', function () {
    fakeGateway();
    postSubscription($this->member, ['plan_id' => $this->plan->id])->assertCreated();

    $this->plan->update(['price_cents' => 3999]);

    expect(Invoice::sole()->amount_cents)->toBe(2999);
});

it('validates the request before anything is written or charged', function (array $body, ?string $key, string $field) {
    $gateway = fakeGateway();
    $inactive = Plan::factory()->inactive()->create();
    $body = array_map(fn ($v) => $v === 'INACTIVE' ? $inactive->id : $v, $body);

    postSubscription($this->member, $body, $key)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($gateway->charges)->toBeEmpty()
        ->and(Subscription::count())->toBe(0);
})->with([
    'missing Idempotency-Key header' => [['plan_id' => 1], null, 'idempotency_key'],
    'missing plan_id' => [[], 'key-123', 'plan_id'],
    'unknown plan' => [['plan_id' => 999999], 'key-123', 'plan_id'],
    'inactive plan' => [['plan_id' => 'INACTIVE'], 'key-123', 'plan_id'],
]);

it('rejects an unauthenticated request with 401', function () {
    $gateway = fakeGateway();

    $this->postJson('/api/subscriptions', ['plan_id' => $this->plan->id], ['Idempotency-Key' => 'key-123'])
        ->assertUnauthorized();

    expect($gateway->charges)->toBeEmpty();
});
