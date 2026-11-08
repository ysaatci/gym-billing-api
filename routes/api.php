<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Everything in this file is prefixed with /api (bootstrap/app.php).

// Public
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Authenticated: auth:sanctum reads "Authorization: Bearer <token>", finds the token row,
// and sets $request->user(). Missing or invalid token -> 401 before any controller runs.
Route::middleware('auth:sanctum')->group(function () {
    // Owner or admin: checked by SubscriptionPolicy inside the controller.
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show']);
});
