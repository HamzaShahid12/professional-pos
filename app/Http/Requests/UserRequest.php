<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $businessId = app(TenantContext::class)->businessId();
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => [$target ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
        ];
    }
}
