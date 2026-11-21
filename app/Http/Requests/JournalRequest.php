<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accounting.manage');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();
        $amount = ['nullable', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'];

        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('business_id', $business)],
            'lines.*.debit' => $amount,
            'lines.*.credit' => $amount,
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.debit.regex' => 'Amounts allow at most 4 decimal places.', 'lines.*.credit.regex' => 'Amounts allow at most 4 decimal places.'];
    }
}
