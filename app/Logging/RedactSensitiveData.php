<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Safety net that masks secrets and personal data before a log record is written.
 * The real rule is still to log IDs and never pass personal data to the logger;
 * this catches mistakes like someone later logging $request->all().
 */
class RedactSensitiveData
{
    public const MASK = '[REDACTED]';

    private const SENSITIVE_KEY = '/pass(word)?|token|secret|authorization|cookie|api_?key|email|card|cvc|cvv|iban/i';

    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

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
