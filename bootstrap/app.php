<?php

use App\Exceptions\IdempotencyKeyInUseException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Http\Middleware\AssignRequestId;
use App\Payments\PaymentFailedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only, no login page: guests get a 401, never a redirect (which would 500).
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // First, so even a 401 or 404 has a request ID.
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected outcomes, already logged by the service as one line; keep them
        // out of the error log. Everything else is logged with its stack trace, and the
        // client gets a bare {"message": "Server Error"} (APP_DEBUG=false in production).
        $exceptions->dontReport([
            PaymentFailedException::class,
            IdempotencyKeyInUseException::class,
            IdempotencyKeyMismatchException::class,
        ]);

        // The one place that maps domain exceptions to HTTP.
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
