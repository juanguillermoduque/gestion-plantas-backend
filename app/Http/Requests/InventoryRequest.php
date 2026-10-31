<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locationId' => ['sometimes', 'integer', 'min:1', 'exists:locations,id'],
            'supplyId' => ['sometimes', 'integer', 'min:1', 'exists:supplies,id'],
            'type' => ['required', Rule::in(['transfer', 'add', 'loss'])],
            'from' => ['required_if:type,transfer,loss', 'nullable', Rule::in(['unclassified', 'growing', 'mother', 'ready'])],
            'to' => ['required_if:type,transfer,add', 'nullable', Rule::in(['unclassified', 'growing', 'mother', 'ready'])],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000', 'decimal:0,3'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
