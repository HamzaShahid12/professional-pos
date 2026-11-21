<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                $role ? 'prohibited' : 'required', 'string', 'max:60',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }
}
