<?php

namespace App\Http\Requests;

use App\Services\Parties\PaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a purchase order (optionally receiving it at once). */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $editing = $this->route('purchase') !== null;

        return (bool) $this->user()?->can($editing ? 'purchases.update' : 'purchases.create');
    }

    public function rules(): array
    {
        $b = app(TenantContext::class)->businessId();
        $decimal = 'regex:/^\d+(\.\d{1,4})?$/';

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where('business_id', $b)],
            'order_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'receive_now' => ['sometimes', 'boolean'],
            'received_at' => ['nullable', 'date'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'payment_method' => ['nullable', Rule::in(PaymentService::METHODS)],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $b)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', $decimal],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', $decimal],
            'items.*.batch_number' => ['nullable', 'string', 'max:64'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return ['items.*.quantity.regex' => 'Quantity allows at most 4 decimal places.', 'items.*.unit_cost.regex' => 'Cost allows at most 4 decimal places.'];
    }
}
