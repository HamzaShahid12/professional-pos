<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Customer / supplier create and update. The party type comes from the route name prefix. */
class PartyRequest extends FormRequest
{
    public function partyType(): string
    {
        return str_starts_with((string) $this->route()?->getName(), 'suppliers.') ? 'supplier' : 'customer';
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->partyType() === 'supplier' ? 'suppliers.manage' : 'customers.manage');
    }

    public function rules(): array
    {
        $business = app(TenantContext::class)->businessId();
        $table = $this->partyType() === 'supplier' ? 'suppliers' : 'customers';
        $editing = $this->route('party') !== null;

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if (! $editing) {
            $rules['code'] = ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique($table, 'code')->where('business_id', $business)];
            $rules['opening_balance'] = ['nullable', 'numeric', 'between:-9999999999,9999999999', 'regex:/^-?\d+(\.\d{1,4})?$/'];
        }

        if ($this->partyType() === 'supplier') {
            $rules['contact_person'] = ['nullable', 'string', 'max:150'];
            $rules['payment_terms_days'] = ['nullable', 'integer', 'min:0', 'max:365'];
        } else {
            $rules['credit_limit'] = ['nullable', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }

    /** Blank optional numbers become 0 so NOT NULL columns never receive null. */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        foreach (['credit_limit', 'payment_terms_days'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = 0;
            }
        }

        return $key ? data_get($data, $key, $default) : $data;
    }
}
