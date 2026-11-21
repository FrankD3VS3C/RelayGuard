<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],

            'event_type' => [
                'required',
                'string',
                'max:150',
            ],

            'payload' => [
                'required',
                'array',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',

            'idempotency_key.regex' => 'The Idempotency-Key contains invalid characters.',

            'event_type.required' => 'The event type is required.',

            'payload.required' => 'The event payload is required.',

            'payload.array' => 'The event payload must be a JSON object or array.',
        ];
    }
}
