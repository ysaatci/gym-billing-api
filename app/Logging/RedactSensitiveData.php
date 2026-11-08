<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Safety net that scrubs secrets and personal data from log records before they are
 * written. Attached to log channels with 'tap' in config/logging.php.
 *
 * The first rule is still "don't pass it to the logger": we log user, subscription and
 * invoice IDs, never emails or card data. This catches mistakes, e.g. someone later
 * logging $request->all() or a whole model.
 */
class RedactSensitiveData
{
    public const MASK = '[REDACTED]';

    /** Any context key whose name matches this is masked, at any depth. */
    private const SENSITIVE_KEY = '/pass(word)?|token|secret|authorization|cookie|api_?key|email|card|cvc|cvv|iban/i';

    /** Email addresses inside the message text itself. */
    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    /** Called by Laravel when the channel is created. */
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record) => $this->redact($record));
    }

    public function redact(LogRecord $record): LogRecord
    {
        return $record->with(
            message: preg_replace(self::EMAIL, self::MASK, $record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            } elseif (is_string($value)) {
                $data[$key] = preg_replace(self::EMAIL, self::MASK, $value);
            }
        }

        return $data;
    }
}
