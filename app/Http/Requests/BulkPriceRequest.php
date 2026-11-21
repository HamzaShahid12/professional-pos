<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function rules(): array
    {
        $businessId = app(\App\Support\TenantContext::class)->businessId();

        return [
            'scope' => ['required', Rule::in(['all', 'category', 'brand', 'selected'])],
            'category_id' => ['required_if:scope,category', 'nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $businessId)],
            'brand_id' => ['required_if:scope,brand', 'nullable', 'integer', Rule::exists('brands', 'id')->where('business_id', $businessId)],
            'product_ids' => ['required_if:scope,selected', 'nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'field' => ['required', Rule::in(Product::PRICE_FIELDS)],
            'mode' => ['required', Rule::in(['percent', 'add', 'set'])],
            'value' => ['required', 'numeric', 'between:-99999999999,99999999999'],
            'reason' => ['nullable', 'string', 'max:120'],
        ];
    }
}
