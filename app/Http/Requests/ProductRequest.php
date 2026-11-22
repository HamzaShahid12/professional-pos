<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Rules\UniqueCode;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        $businessId = app(TenantContext::class)->businessId();
        $product = $this->route('product');
        $own = fn (string $table) => Rule::exists($table, 'id')->where('business_id', $businessId)->whereNull('deleted_at');
        $price = ['numeric', 'min:0', 'max:99999999999999'];

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-\/]+$/', new UniqueCode('sku', $product?->id)],
            'barcode' => ['nullable', 'string', 'max:64', new UniqueCode('barcode', $product?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', $own('categories')],
            'brand_id' => ['nullable', 'integer', $own('brands')],
            'unit_id' => ['required', 'integer', $own('units')],
            'purchase_price' => ['required', ...$price],
            'selling_price' => ['required', ...$price],
            'wholesale_price' => ['nullable', ...$price],
            'min_selling_price' => ['nullable', ...$price, 'lte:selling_price'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            // The web form is sent as multipart, which drops empty arrays. It sends this flag so that unticking every
            // tax (or removing every variant) is understood as "none" and not as "unchanged".
            'sync_relations' => ['sometimes', 'boolean'],
            'tax_ids' => ['sometimes', 'array'],
            'tax_ids.*' => ['integer', $own('taxes')],
            'is_active' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_image_ids' => ['sometimes', 'array'],
            'remove_image_ids.*' => ['integer'],
            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.purchase_price' => ['nullable', ...$price],
            'variants.*.selling_price' => ['nullable', ...$price],
            'variants.*.wholesale_price' => ['nullable', ...$price],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];

        foreach ((array) $this->input('variants', []) as $i => $variant) {
            $variantId = isset($variant['id']) ? (int) $variant['id'] : null;
            $rules["variants.$i.sku"] = ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-\/]+$/', new UniqueCode('sku', null, $variantId)];
            $rules["variants.$i.barcode"] = ['nullable', 'string', 'max:64', new UniqueCode('barcode', null, $variantId)];
        }

        return $rules;
    }

    /** SKUs and barcodes must also be distinct within the submitted form (product + all its variants). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (['sku', 'barcode'] as $field) {
                $seen = [];
                $entries = [[$field, $this->input($field)]];
                foreach ((array) $this->input('variants', []) as $i => $variant) {
                    $entries[] = ["variants.$i.$field", $variant[$field] ?? null];
                }
                foreach ($entries as [$key, $value]) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $norm = mb_strtolower((string) $value);
                    if (isset($seen[$norm]) && ! $v->errors()->has($key)) {
                        $v->errors()->add($key, "This {$field} is repeated in the form.");
                    }
                    $seen[$norm] = true;
                }
            }
        });
    }
}