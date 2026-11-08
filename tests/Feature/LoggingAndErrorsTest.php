<?php

use App\Models\Plan;
use App\Models\User;
use App\Payments\ChargeResult;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * Capture what a channel writes, keeping the channel's real configuration (formatter,
 * redaction tap). Processors run before every handler, so the TestHandler sees the
 * records exactly as they would be written to the file.
 */
function captureLogs(?string $channel = null): TestHandler
{
    $handler = new TestHandler;
    Log::channel($channel)->getLogger()->pushHandler($handler);

    return $handler;
}

/** One log record flattened to text, to search for things that must never appear. */
function loggedText(TestHandler $handler): string
{
    return collect($handler->getRecords())
        ->map(fn (LogRecord $r) => json_encode($r->toArray()))
        ->implode("\n");
}

beforeEach(function () {
    $this->member = User::factory()->create(['email' => 'ada@example.com']);
    $this->plan = Plan::factory()->create();
});

describe('request ID', function () {
    it('adds a generated X-Request-Id to every response, including errors', function () {
        $response = $this->getJson('/api/subscriptions/1')->assertUnauthorized();

        expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('keeps a well-formed incoming X-Request-Id and replaces a malformed one', function () {
        $this->getJson('/api/subscriptions/1', ['X-Request-Id' => 'lb-12345678'])
            ->assertHeader('X-Request-Id', 'lb-12345678');

        // Client text ends up in logs and headers, so anything unexpected is replaced.
        expect($this->getJson('/api/subscriptions/1', ['X-Request-Id' => '<script>alert(1)</script>'])->headers->get('X-Request-Id'))
            ->toMatch('/^[0-9a-f-]{36}$/');
    });
});

describe('billing log', function () {
    it('logs a successful payment with IDs and the request ID, never the email', function () {
        app()->instance(PaymentGateway::class, new FakePaymentGateway);
        $logs = captureLogs('billing');

        $response = $this->actingAs($this->member)
            ->postJson('/api/subscriptions', ['plan_id' => $this->plan->id], ['Idempotency-Key' => 'key-1'])
            ->assertCreated();

        expect($logs->hasInfoThatContains('payment.succeeded'))->toBeTrue();
        $record = $logs->getRecords()[0];
        expect($record->context)->toMatchArray([
            'user_id' => $this->member->id,
            'subscription_id' => $response->json('data.id'),
            'amount_cents' => 2999,
        ])
            ->and(loggedText($logs))->toContain($response->headers->get('X-Request-Id'))
            ->and(loggedText($logs))->not->toContain('ada@example.com');
    });

    it('logs a decline as a warning with the reason code', function () {
        app()->instance(PaymentGateway::class, new FakePaymentGateway(declineAll: true));
        $logs = captureLogs('billing');

        $this->actingAs($this->member)
            ->postJson('/api/subscriptions', ['plan_id' => $this->plan->id], ['Idempotency-Key' => 'key-1'])
            ->assertStatus(402);

        expect($logs->hasWarningThatContains('payment.declined'))->toBeTrue()
            ->and($logs->getRecords()[0]->context['reason'])->toBe('card_declined');
    });
});

it('logs a failed login without the email or the password tried', function () {
    $logs = captureLogs(); // default channel

    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertUnauthorized();

    expect($logs->hasWarningThatContains('auth.login_failed'))->toBeTrue()
        ->and(loggedText($logs))->not->toContain('ada@example.com')
        ->and(loggedText($logs))->not->toContain('wrong-password');
});

it('answers an unexpected failure with a bare 500, logs the details, and leaves the key in processing', function () {
    config(['app.debug' => false]); // production setting

    // A gateway that breaks its contract (throws something other than PaymentFailedException).
    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function charge(int $amountCents, string $currency, string $idempotencyKey): ChargeResult
        {
            throw new RuntimeException('Connection reset by peer while talking to provider');
        }
    });
    $logs = captureLogs();

    $response = $this->actingAs($this->member)
        ->postJson('/api/subscriptions', ['plan_id' => $this->plan->id], ['Idempotency-Key' => 'key-1']);

    // The client learns nothing about the internals...
    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

    // ...but the log has the exception and the request ID to find it.
    expect(loggedText($logs))->toContain('Connection reset by peer')
        ->and(loggedText($logs))->toContain($response->headers->get('X-Request-Id'));

    // Known limitation: the outcome is unknown, so the key stays "processing" and a
    // retry gets 409 until a webhook or reconciliation job settles it.
    $this->actingAs($this->member)
        ->postJson('/api/subscriptions', ['plan_id' => $this->plan->id], ['Idempotency-Key' => 'key-1'])
        ->assertStatus(409);
});
