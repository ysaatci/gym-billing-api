<?php

namespace App\Http\Requests;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Validate the Idempotency-Key header with the same rules and error format as the body.
     * merge() overwrites any "idempotency_key" in the body: only the header counts.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
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
     * Detects the same key being reused for a different request. Fields are listed and
     * cast explicitly so {"plan_id": 1} and {"plan_id": "1"} hash the same.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'plan_id' => (int) $this->validated('plan_id'),
        ]));
    }
}
