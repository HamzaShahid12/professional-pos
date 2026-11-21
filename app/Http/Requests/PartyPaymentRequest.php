<?php

namespace App\Http\Requests;

use App\Services\Parties\PaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Receipt from a customer / payment to a supplier. */
class PartyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = str_starts_with((string) $this->route()?->getName(), 'suppliers.') ? 'suppliers.manage' : 'customers.manage';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'method' => ['required', Rule::in(PaymentService::METHODS)],
            'method_reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
