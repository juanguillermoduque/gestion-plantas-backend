<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $existing = $this->route('product');
        if (! $this->has('inventoryMode')) {
            $this->merge(['inventoryMode' => $existing instanceof Product ? $existing->inventory_mode : 'bom']);
        }
        if (! $this->has('stockUnitsPerUnit') && $this->has('plantsPerUnit')) {
            $this->merge(['stockUnitsPerUnit' => $this->input('plantsPerUnit')]);
        }
        if (! $this->has('stockUnitsPerUnit')) {
            $this->merge(['stockUnitsPerUnit' => $this->input('inventoryMode') === 'legacy' && $existing instanceof Product ? $existing->plants_per_unit : 0]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'id' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'stockUnitsPerUnit' => ['required', 'integer', 'min:0', 'max:100'],
            'plantsPerUnit' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'basePrice' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'minutes' => ['required', 'numeric', 'min:0', 'max:600', 'decimal:0,2'],
            'inventoryMode' => ['required', Rule::in(['legacy', 'bom'])],
            'extraCost' => ['sometimes', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,4'],
            'components' => ['present', 'array', 'max:30'],
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.minQty' => ['required', 'integer', 'min:1', 'max:100000', 'distinct'],
            'tiers.*.price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'active' => ['sometimes', 'boolean'],
        ];
        if ($this->input('inventoryMode') === 'legacy') {
            $rules['components'][] = 'min:1';

            return $rules + ['components.*.name' => ['required', 'string', 'max:100'], 'components.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'], 'components.*.unitCost' => ['required', 'integer', 'min:0', 'max:1000000000']];
        }

        return $rules + ['components.*.supplyId' => ['required', 'integer', 'min:1', 'distinct', 'exists:supplies,id'], 'components.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000', 'decimal:0,3']];
    }
}
