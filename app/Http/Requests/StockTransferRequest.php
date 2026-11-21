<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.transfer');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();
        $warehouse = Rule::exists('warehouses', 'id')->where('business_id', $business)->whereNull('deleted_at');

        return [
            'from_warehouse_id' => ['required', 'integer', $warehouse],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', $warehouse],
            'transferred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $business)->whereNull('deleted_at')],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $business)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    public function messages(): array
    {
        return ['items.*.quantity.regex' => 'Quantity allows at most 4 decimal places.'];
    }
}
