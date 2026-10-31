<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'plantsPerUnit' => ['required', 'integer', 'min:1', 'max:100'],
            'basePrice' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'minutes' => ['required', 'numeric', 'min:0', 'max:600', 'decimal:0,2'],
            'components' => ['required', 'array', 'min:1', 'max:30'],
            'components.*.name' => ['required', 'string', 'max:100'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'components.*.unitCost' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.minQty' => ['required', 'integer', 'min:1', 'max:100000', 'distinct'],
            'tiers.*.price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
