<?php

use App\Exceptions\IdempotencyKeyInUseException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Payments\PaymentFailedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only app, there is no login page: an unauthenticated request gets a 401,
        // never a redirect. Without this, auth:sanctum tries to redirect to route('login')
        // when the client doesn't send "Accept: application/json", and fails with a 500.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes always get JSON errors (401, 403, 404, 422 ...) instead of HTML pages
        // or redirects to a login page, even if the client forgot "Accept: application/json".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain exceptions -> HTTP. The exceptions themselves know nothing about HTTP;
        // this is the one place that maps them to status codes.
        $exceptions->render(fn (PaymentFailedException $e) => response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
        ], 402));

        $exceptions->render(fn (IdempotencyKeyInUseException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 409));

        $exceptions->render(fn (IdempotencyKeyMismatchException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 422));
    })->create();
