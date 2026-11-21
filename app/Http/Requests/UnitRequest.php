<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Illuminate\Validation\Rule;

class UnitRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function routeParam(): string
    {
        return 'unit';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'short_name' => ['required', 'string', 'max:12', Rule::unique('units', 'short_name')->where('business_id', $this->businessId())->ignore($this->ignoreId())],
            'allow_decimal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
