<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where the response of the main request goes out.
 *
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'plan' => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ],
            'user_id' => $this->user_id, // the ID, never the email
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'amount_cents' => $this->invoice->amount_cents,
                'currency' => $this->invoice->currency,
                'status' => $this->invoice->status->value,
            ]),
        ];
    }
}
