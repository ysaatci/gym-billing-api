<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Same answer for "no such user" and "wrong password", so the response doesn't
        // reveal which emails are registered.
        // Not built: the timing still differs (no hash check for unknown emails), and
        // there's no rate limiting yet.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            Log::warning('auth.login_failed', ['user_id' => $user?->id]); // never the email or password

            throw new AuthenticationException('Invalid credentials.');
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }
}
