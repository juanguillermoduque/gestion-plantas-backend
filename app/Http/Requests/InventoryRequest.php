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
            'type' => ['required', Rule::in(['transfer', 'add', 'loss'])],
            'from' => ['required_if:type,transfer,loss', 'nullable', Rule::in(['unclassified', 'growing', 'mother', 'ready'])],
            'to' => ['required_if:type,transfer,add', 'nullable', Rule::in(['unclassified', 'growing', 'mother', 'ready'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
