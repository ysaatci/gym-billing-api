<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable(['user_id', 'plan_id', 'status', 'started_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
        ];
    }

    /**
     * Status changes go through these methods only, so an invalid transition
     * (e.g. failed -> active) is a loud error instead of silently corrupt billing data.
     * An enum plus two guarded methods is enough for three states (no State pattern).
     */
    public function markActive(): void
    {
        $this->assertPending();
        $this->update(['status' => SubscriptionStatus::Active, 'started_at' => now()]);
    }

    public function markFailed(): void
    {
        $this->assertPending();
        $this->update(['status' => SubscriptionStatus::Failed]);
    }

    private function assertPending(): void
    {
        if ($this->status !== SubscriptionStatus::Pending) {
            throw new LogicException("Subscription {$this->id} is {$this->status->value}, expected pending.");
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * One invoice per subscription for now: the first payment.
     * Recurring renewals would make this a HasMany (see README "Known limitations").
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
