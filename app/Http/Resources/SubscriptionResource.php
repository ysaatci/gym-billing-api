<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single place that decides what a subscription looks like in JSON.
 * This is where the response of the main request goes out.
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
            // Only user_id, never the email: the owner already knows who they are,
            // and admins can look the user up by ID.
            'user_id' => $this->user_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                // Minor units + currency, exactly as stored (D8). Formatting is the client's job.
                'amount_cents' => $this->invoice->amount_cents,
                'currency' => $this->invoice->currency,
                'status' => $this->invoice->status->value,
            ]),
        ];
    }
}
