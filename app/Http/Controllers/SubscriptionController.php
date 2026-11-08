<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    /**
     * The main request. Validation has already run (StoreSubscriptionRequest);
     * failures are exceptions, mapped to JSON in bootstrap/app.php.
     */
    public function store(StoreSubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->subscribe(
            $request->user(),
            $request->plan(),
            $request->idempotencyKey(),
            $request->fingerprint(),
        );

        return (new SubscriptionResource($subscription->load(['plan', 'invoice'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        // 404 for unknown IDs, 403 for other people's: a caller can tell which IDs exist.
        // Acceptable here; a public API would answer 404 for both.
        Gate::authorize('view', $subscription);

        return new SubscriptionResource($subscription->load(['plan', 'invoice']));
    }
}
