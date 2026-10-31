<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('patch') ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'unit' => [...$required, 'string', 'max:40'],
            'kind' => [...$required, Rule::in(['stock', 'expense'])],
            'unitCost' => [...$required, 'numeric', 'min:0', 'max:1000000000', 'decimal:0,4'],
            'minStock' => ['sometimes', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,3'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
