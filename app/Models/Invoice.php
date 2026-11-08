<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['subscription_id', 'amount_cents', 'currency', 'status', 'gateway_reference', 'failure_reason'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => InvoiceStatus::class,
        ];
    }

    public function markPaid(string $gatewayReference): void
    {
        $this->assertOpen();
        $this->update(['status' => InvoiceStatus::Paid, 'gateway_reference' => $gatewayReference]);
    }

    public function markVoid(string $failureReason): void
    {
        $this->assertOpen();
        $this->update(['status' => InvoiceStatus::Void, 'failure_reason' => $failureReason]);
    }

    private function assertOpen(): void
    {
        if ($this->status !== InvoiceStatus::Open) {
            throw new LogicException("Invoice {$this->id} is {$this->status->value}, expected open.");
        }
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
