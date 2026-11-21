<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Creates a count (warehouse + notes) or saves counted quantities, depending on the route. */
class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.adjust');
    }

    public function rules(): array
    {
        if ($this->route('count')) {
            return [
                'items' => ['required', 'array', 'min:1', 'max:5000'],
                'items.*.id' => ['required', 'integer'],
                'items.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            ];
        }

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(TenantContext::class)->businessId())->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
