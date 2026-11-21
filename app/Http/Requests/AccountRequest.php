<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accounting.manage');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();
        $ignore = $this->route('account')?->id;

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('accounts', 'code')->where('business_id', $business)->ignore($ignore)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('business_id', $business)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
