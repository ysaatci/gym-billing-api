<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Thin on purpose: HTTP in, HTTP out. Validation is in the FormRequest, rules in the
 * Policy, business logic in SubscriptionService, response shape in SubscriptionResource.
 */
class SubscriptionController extends Controller
{
    /**
     * POST /api/subscriptions (authenticated member, Idempotency-Key header required)
     * THE MAIN REQUEST. Entry point after routing, auth:sanctum and validation.
     *
     * Laravel builds both arguments: it validates StoreSubscriptionRequest before this
     * method runs, and the container creates SubscriptionService with its dependencies.
     * Failures are exceptions, turned into JSON in bootstrap/app.php (402 / 409 / 422).
     */
    public function store(StoreSubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->subscribe(
            $request->user(),
            $request->plan(),
            $request->idempotencyKey(),
            $request->fingerprint(),
        );

        // A replay returns the same 201 and body as the first request.
        return (new SubscriptionResource($subscription->load(['plan', 'invoice'])))
            ->response()
            ->setStatusCode(201);
    }

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
