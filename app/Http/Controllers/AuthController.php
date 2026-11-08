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
    /**
     * POST /api/auth/register (public)
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // validated() returns only the fields that have rules, so an extra
        // "role": "admin" in the body is dropped here already (and role isn't fillable either).
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Hash::make = bcrypt with a random salt and a cost factor (config/hashing.php).
            // Only the hash is stored. The model's 'hashed' cast sees it is already hashed
            // and leaves it alone, so it isn't hashed twice.
            'password' => Hash::make($data['password']),
        ]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * POST /api/auth/login (public). Returns a bearer token for the other endpoints.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Eloquent builds: select * from users where email = ? limit 1 (bound parameter, no injection).
        $user = User::where('email', $request->validated('email'))->first();

        // Hash::check re-hashes the given password with the salt and cost stored inside
        // the bcrypt hash and compares the results in constant time.
        // Same error for "no such user" and "wrong password", so the response doesn't reveal
        // which emails are registered. Known gap: when the user doesn't exist we skip the
        // (slow) hash check, so response timing still differs. No rate limiting either
        // (see README "Known limitations").
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // Never log the email or the password that was tried. The user ID (when the
            // account exists) and the request ID are enough to spot a brute-force attempt.
            Log::warning('auth.login_failed', ['user_id' => $user?->id]);

            throw new AuthenticationException('Invalid credentials.'); // rendered as 401 JSON
        }

        // Sanctum: generates a random token, stores only its SHA-256 hash in
        // personal_access_tokens, and returns "{token id}|{plain token}" to the client once.
        // On later requests auth:sanctum looks the row up by id and compares hashes (D10).
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
