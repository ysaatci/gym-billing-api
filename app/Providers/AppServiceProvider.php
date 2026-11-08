<?php

namespace App\Providers;

use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place that decides which PaymentGateway the app uses. Anything that
        // type-hints the interface (SubscriptionService) gets this. Switching to a real
        // provider = a new class implementing PaymentGateway + this one line.
        // Tests swap it with app()->instance(PaymentGateway::class, ...).
        $this->app->bind(PaymentGateway::class, fn () => new FakePaymentGateway(
            declineAll: config('services.fake_gateway.decline'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
