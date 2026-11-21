<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('currency'));
    }

    public function rules(): array
    {
        return [
            'rate' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'effective_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
