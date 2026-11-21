<?php

namespace App\Http\Requests;

use App\Services\Cash\ExpenseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.manage');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $business)],
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('business_id', $business)],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'method' => ['required', Rule::in(ExpenseService::METHODS)],
            'payee' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'The amount allows at most 4 decimal places.'];
    }
}
