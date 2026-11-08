<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Runs before the controller. If validation fails, Laravel stops the request and
 * returns 422 JSON with the field errors; the controller is never called.
 */
class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // unique:users,email runs a bound-parameter query: select count(*) from users where email = ?
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)],
            // No 'role' rule on purpose: role is never accepted from the client (D9).
        ];
    }
}
