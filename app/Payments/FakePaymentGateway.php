<?php

namespace App\Payments;

/**
 * Stand-in for a real provider, for local use and tests. Never touches the network.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /**
     * Every call, so tests can prove a retry did not charge twice.
     *
     * @var list<array{amount_cents: int, currency: string, idempotency_key: string}>
     */
    public array $charges = [];

    public function __construct(
        private readonly bool $declineAll = false,
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

        return new ChargeResult('fake_ch_'.substr(hash('sha256', $idempotencyKey), 0, 24));
    }
}
