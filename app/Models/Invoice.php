<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'amount_cents', 'currency', 'status', 'gateway_reference'])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => InvoiceStatus::class,
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
