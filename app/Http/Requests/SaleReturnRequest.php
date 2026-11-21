<?php

namespace App\Http\Requests;

use App\Services\Sales\SaleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales.return');
    }

    public function rules(): array
    {
        return [
            'refund_method' => ['required', Rule::in(array_merge(['account'], SaleService::METHODS))],
            'return_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
