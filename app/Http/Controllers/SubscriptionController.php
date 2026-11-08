<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    /**
     * GET /api/subscriptions/{subscription} (owner or admin)
     *
     * Route model binding has already loaded the row (select * from subscriptions where id = ?)
     * or returned 404 if it doesn't exist.
     */
    public function show(Subscription $subscription): SubscriptionResource
    {
        // Calls SubscriptionPolicy::view(). If it returns false, Laravel throws
        // AuthorizationException, rendered as 403 JSON.
        // Trade-off: 404 for unknown IDs but 403 for other people's, so a caller can tell
        // which IDs exist (D11).
        Gate::authorize('view', $subscription);

        return new SubscriptionResource($subscription->load(['plan', 'invoice']));
    }
}
