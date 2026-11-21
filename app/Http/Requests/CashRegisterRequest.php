<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.manage');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('cash_registers', 'name')->where('business_id', $business)->ignore($this->route('register')?->id)],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $business)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
