<?php

namespace App\Http\Requests;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/subscriptions. Runs before the controller; on failure the
 * client gets 422 with field errors and nothing else happens.
 */
class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Copy the Idempotency-Key header into the validated data, so the header is checked
     * by the same rules and error format as the body. merge() overwrites any
     * "idempotency_key" the client put in the body: only the header counts.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            // Bound-parameter query: select count(*) from plans where id = ? and is_active = ?
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            // Any unique string up to 255 chars; clients should send a UUID v4.
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'plan_id.exists' => 'The selected plan does not exist or is no longer available.',
        ];
    }

    public function plan(): Plan
    {
        return Plan::findOrFail($this->validated('plan_id'));
    }

    public function idempotencyKey(): string
    {
        return $this->validated('idempotency_key');
    }

    /**
     * SHA-256 of the request body, to detect the same key being reused for a different
     * request (D5). Fields are listed and cast explicitly so {"plan_id": 1} and
     * {"plan_id": "1"} give the same hash.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'plan_id' => (int) $this->validated('plan_id'),
        ]));
    }
}
