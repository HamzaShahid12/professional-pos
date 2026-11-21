<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Validation\Rule;

class WarehouseRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    protected function routeParam(): string
    {
        return 'warehouse';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('warehouses', 'code')->where('business_id', $this->businessId())->ignore($this->ignoreId())],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $this->businessId())->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
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
