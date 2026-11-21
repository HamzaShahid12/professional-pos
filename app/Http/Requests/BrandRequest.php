<?php

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Validation\Rule;

class BrandRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Brand::class;
    }

    protected function routeParam(): string
    {
        return 'brand';
    }

    public function rules(): array
    {
        return [
            // Includes soft-deleted rows because the DB unique index does too.
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->where('business_id', $this->businessId())->ignore($this->ignoreId())],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
