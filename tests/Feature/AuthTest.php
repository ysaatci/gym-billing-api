<?php

use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('POST /api/auth/register', function () {
    it('creates a member and stores only a password hash', function () {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'ada@example.com');
        expect($user->password)->not->toBe('correct-horse-battery')
            ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
    });

    it('ignores a role sent by the client', function () {
        $this->postJson('/api/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'correct-horse-battery',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.role', 'member');

        expect(User::firstWhere('email', 'mallory@example.com')->role)->toBe(UserRole::Member);
    });

    it('rejects invalid input with 422 and field errors', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    });
});

describe('POST /api/auth/login', function () {
    it('returns a bearer token that authenticates later requests', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']); // factory password: "password"

        $token = $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json('token');

        // Only the SHA-256 hash of the token is stored, never the token itself.
        [, $plain] = explode('|', $token, 2);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'token' => hash('sha256', $plain),
        ]);

        // Use the real token (not actingAs) to prove auth:sanctum accepts it.
        $subscription = Subscription::factory()->for($user)->create();
        $this->withToken($token)
            ->getJson("/api/subscriptions/{$subscription->id}")
            ->assertOk();
    });

    it('returns the same 401 for a wrong password and an unknown email', function (string $email, string $password) {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/login', compact('email', 'password'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);
    })->with([
        'wrong password' => ['ada@example.com', 'not-the-password'],
        'unknown email' => ['nobody@example.com', 'password'],
    ]);
});
