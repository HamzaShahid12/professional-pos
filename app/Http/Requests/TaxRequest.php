<?php

namespace App\Http\Requests;

use App\Models\Tax;
use Illuminate\Validation\Rule;

class TaxRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Tax::class;
    }

    protected function routeParam(): string
    {
        return 'tax';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('taxes', 'name')->where('business_id', $this->businessId())->ignore($this->ignoreId())],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_inclusive' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
