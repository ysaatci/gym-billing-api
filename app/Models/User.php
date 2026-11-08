<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// `role` is deliberately NOT fillable: User::create($request->all()) can never
// turn a member into an admin through mass assignment (D9).
#[Fillable(['name', 'email', 'password'])]
// Never serialized into JSON responses, even by accident.
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // HasApiTokens (Sanctum): createToken() stores a SHA-256 hash of a random token
    // in personal_access_tokens and returns the plain token to the client once (D10).
    use HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // 'hashed' cast: assigning a plain password runs Hash::make() (bcrypt) on it
            // before it is saved. An already-hashed value is left alone.
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
