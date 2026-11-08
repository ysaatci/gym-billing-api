<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_cents' => 2999,
            'currency' => 'EUR',
            'status' => InvoiceStatus::Paid,
            'gateway_reference' => 'fake_'.fake()->uuid(),
        ];
    }
}
