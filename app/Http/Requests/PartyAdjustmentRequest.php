<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Manual signed ledger correction with a mandatory reason. */
class PartyAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = str_starts_with((string) $this->route()?->getName(), 'suppliers.') ? 'suppliers.manage' : 'customers.manage';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-9999999999,9999999999', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'entry_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
