<?php

use App\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

function makeLogRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'billing', Level::Info, $message, $context, $extra);
}

it('masks sensitive keys at any depth and keeps IDs', function () {
    $redacted = (new RedactSensitiveData)->redact(makeLogRecord('login attempt', [
        'user_id' => 42,
        'password' => 'hunter2-hunter2',
        'request' => [
            'email' => 'ada@example.com',
            'card_number' => '4242424242424242',
            'headers' => ['Authorization' => 'Bearer 1|abc'],
        ],
        'api_key' => 'not-a-real-key',
    ]));

    expect($redacted->context)->toBe([
        'user_id' => 42,
        'password' => '[REDACTED]',
        'request' => [
            'email' => '[REDACTED]',
            'card_number' => '[REDACTED]',
            'headers' => ['Authorization' => '[REDACTED]'],
        ],
        'api_key' => '[REDACTED]',
    ]);
});

it('masks email addresses inside the message and inside plain values', function () {
    $redacted = (new RedactSensitiveData)->redact(makeLogRecord(
        'Charge failed for ada@example.com',
        ['note' => 'contact bob@example.org'],
        ['request_id' => 'abc-123'],
    ));

    expect($redacted->message)->toBe('Charge failed for [REDACTED]')
        ->and($redacted->context['note'])->toBe('contact [REDACTED]')
        ->and($redacted->extra['request_id'])->toBe('abc-123');
});
