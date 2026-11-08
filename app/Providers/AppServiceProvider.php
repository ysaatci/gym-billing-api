<?php

namespace App\Providers;

use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place that picks the payment provider.
        $this->app->bind(PaymentGateway::class, fn () => new FakePaymentGateway(
            declineAll: config('services.fake_gateway.decline'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
