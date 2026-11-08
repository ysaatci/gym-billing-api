<?php

namespace App\Payments;

/**
 * Stand-in for a real provider, used locally and in tests. It never talks to the network.
 * It keeps the same contract as a real gateway (see PaymentGateway), so tests that pass
 * against it say something true about the service.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /**
     * Every call, in order. Tests use it to prove a retry did NOT charge twice.
     *
     * @var list<array{amount_cents: int, currency: string, idempotency_key: string}>
     */
    public array $charges = [];

    public function __construct(
        private readonly bool $declineAll = false, // FAKE_GATEWAY_DECLINE=true in .env to try the 402 path
    ) {}

    public function charge(int $amountCents, string $currency, string $idempotencyKey): ChargeResult
    {
        $this->charges[] = [
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($this->declineAll) {
            throw new PaymentFailedException('card_declined');
        }

        // Deterministic per idempotency key, like a real provider returning the same
        // charge when it sees the same key again.
        return new ChargeResult('fake_ch_'.substr(hash('sha256', $idempotencyKey), 0, 24));
    }
}
