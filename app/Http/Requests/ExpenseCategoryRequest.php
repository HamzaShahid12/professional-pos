<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.approve');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->where('business_id', $business)->ignore($this->route('category')?->id)],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('business_id', $business)->where('type', 'expense')->where('is_active', true)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['account_id.exists' => 'Choose an active expense account.'];
    }
}
