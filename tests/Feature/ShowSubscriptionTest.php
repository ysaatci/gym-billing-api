<?php

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->subscription = Subscription::factory()->for($this->owner)->create();
    Invoice::factory()->for($this->subscription)->create();
});

it('shows the subscription with its invoice to the owner', function () {
    $this->actingAs($this->owner)
        ->getJson("/api/subscriptions/{$this->subscription->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->subscription->id)
        ->assertJsonPath('data.invoice.amount_cents', 2999)
        ->assertJsonPath('data.invoice.currency', 'EUR')
        ->assertDontSee('owner@example.com'); // the email appears nowhere in the response
});

it('shows any subscription to an admin', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson("/api/subscriptions/{$this->subscription->id}")
        ->assertOk();
});

it('forbids another member with 403', function () {
    $this->actingAs(User::factory()->create())
        ->getJson("/api/subscriptions/{$this->subscription->id}")
        ->assertForbidden();
});

it('rejects a request without a token with 401 JSON, even without an Accept header', function () {
    // Plain get(), not getJson(): proves the API never redirects to an HTML login page.
    $this->get("/api/subscriptions/{$this->subscription->id}")
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 404 for a subscription that does not exist', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/subscriptions/999999')
        ->assertNotFound();
});
