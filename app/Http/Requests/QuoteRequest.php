<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.productId' => ['required', 'string', 'max:80', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unitPrice' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'hourlyRate' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'deliveryCost' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'deliveryCharged' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'adCost' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'otherCost' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'discount' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
