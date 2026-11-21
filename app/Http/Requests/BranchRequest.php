<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Used for both store (POST) and update (PUT/PATCH). Every foreign id is checked against the current business. */
class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch
            ? $this->user()->can('update', $branch)
            : $this->user()->can('create', Branch::class);
    }

    public function rules(): array
    {
        $businessId = app(TenantContext::class)->businessId();
        $branch = $this->route('branch');
        $businessUsers = fn () => Rule::exists('business_user', 'user_id')->where('business_id', $businessId);

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('branches', 'code')->where('business_id', $businessId)->ignore($branch?->id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_id' => ['nullable', 'integer', $businessUsers()],
            'is_main' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', $businessUsers()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
