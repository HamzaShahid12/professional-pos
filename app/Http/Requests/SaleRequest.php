<?php

namespace App\Http\Requests;

use App\Services\Sales\SaleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cart for quote / hold / complete. Prices and totals are never taken from here, only what was scanned and tendered. */
class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales.create');
    }

    public function rules(): array
    {
        $b = app(TenantContext::class)->businessId();
        $decimal = 'regex:/^\d+(\.\d{1,4})?$/';

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'held_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', $decimal],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.method' => ['required_with:payments', Rule::in(SaleService::METHODS)],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['items.*.quantity.regex' => 'Quantity allows at most 4 decimal places.'];
    }
}
