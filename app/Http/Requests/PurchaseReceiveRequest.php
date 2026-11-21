<?php

namespace App\Http\Requests;

use App\Services\Parties\PaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchases.receive');
    }

    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'payment_method' => ['nullable', Rule::in(PaymentService::METHODS)],
        ];
    }
}
