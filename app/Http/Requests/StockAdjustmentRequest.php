<?php

namespace App\Http\Requests;

use App\Services\Inventory\AdjustmentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.adjust');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $business)->whereNull('deleted_at')],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'reason' => ['required', Rule::in(array_keys(AdjustmentService::REASONS))],
            'adjusted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $business)->whereNull('deleted_at')],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $business)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.batch_number' => ['nullable', 'string', 'max:64'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.quantity.regex' => 'Quantity allows at most 4 decimal places.',
            'items.*.unit_cost.regex' => 'Cost allows at most 4 decimal places.',
        ];
    }
}
