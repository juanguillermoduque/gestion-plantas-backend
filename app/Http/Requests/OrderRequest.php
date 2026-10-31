<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OrderRequest extends QuoteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'customerName' => ['required', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'dueDate' => ['required', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(['quote', 'confirmed'])],
            'paid' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
        ]);
    }
}
