<?php

namespace App\Http\Requests;

use App\Models\Currency;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $currency = $this->route('currency');

        return $currency instanceof Currency
            ? $this->user()->can('update', $currency)
            : $this->user()->can('create', Currency::class);
    }

    public function rules(): array
    {
        $businessId = app(TenantContext::class)->businessId();
        $currency = $this->route('currency');

        return [
            'code' => [
                $currency ? 'prohibited' : 'required', 'string', 'size:3', 'alpha',
                Rule::unique('currencies', 'code')->where('business_id', $businessId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            // Initial rate on create only; later changes go through the rate endpoint so history is kept.
            'exchange_rate' => [$currency ? 'prohibited' : 'required', 'numeric', 'gt:0', 'max:99999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
