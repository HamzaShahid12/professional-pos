<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
