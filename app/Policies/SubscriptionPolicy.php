<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

/**
 * All "who may do what with a subscription" rules live here, in one place.
 * Laravel finds this class by naming convention (App\Models\Subscription ->
 * App\Policies\SubscriptionPolicy); Gate::authorize('view', $subscription) calls view().
 */
class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $user->isAdmin() || $subscription->user_id === $user->id;
    }
}
