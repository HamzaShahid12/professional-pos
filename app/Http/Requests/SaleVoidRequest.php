<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleVoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales.cancel');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
