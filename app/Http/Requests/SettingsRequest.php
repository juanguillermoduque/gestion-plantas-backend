<?php

namespace App\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public const CURRENCIES = ['AED', 'ARS', 'AUD', 'BOB', 'BRL', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CZK', 'DKK', 'DOP', 'EGP', 'EUR', 'GBP', 'GTQ', 'HKD', 'HNL', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JPY', 'KRW', 'MAD', 'MXN', 'MYR', 'NIO', 'NOK', 'NZD', 'PAB', 'PEN', 'PHP', 'PLN', 'PYG', 'RON', 'RSD', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TTD', 'TWD', 'UAH', 'USD', 'UYU', 'VES', 'VND', 'ZAR', 'ZMW', 'ZWG'];

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

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
        return [
            'businessName' => ['required', 'string', 'max:100'],
            'businessType' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'deliveryCoverage' => ['sometimes', 'nullable', 'string', 'max:500'],
            'inventoryUnit' => ['sometimes', 'required', 'string', 'max:60'],
            'inventoryUnitSingular' => ['sometimes', 'required', 'string', 'max:60'],
            'bucketLabels' => ['sometimes', 'array:unclassified,growing,mother,ready', 'required_array_keys:unclassified,growing,mother,ready'],
            'bucketLabels.*' => ['required', 'string', 'max:60'],
            'currency' => ['sometimes', Rule::in(self::CURRENCIES)],
            'locale' => ['sometimes', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,3}(?:-[a-zA-Z]{4})?(?:-(?:[a-zA-Z]{2}|[0-9]{3}))?$/'],
            'timezone' => ['sometimes', Rule::in(DateTimeZone::listIdentifiers())],
            'hourlyRate' => ['required', 'integer', 'between:0,1000000'],
            'monthlyFixedCosts' => ['required', 'integer', 'between:0,1000000000'],
            'moneyPrecision' => ['sometimes', 'integer', 'in:0'],
        ];
    }
}
