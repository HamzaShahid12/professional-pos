<?php

namespace App\Http\Requests;

use App\Models\Business;
use App\Services\Settings\SettingsService;
use App\Support\CurrencyCatalog;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessRequest extends FormRequest
{
    /** The business being edited: the bound route model (API) or the current business (web settings page). null = creating. */
    private function target(): ?Business
    {
        $bound = $this->route('business');
        if ($bound instanceof Business) {
            return $bound;
        }

        return $this->routeIs('settings.business.update') ? app(TenantContext::class)->business() : null;
    }

    public function authorize(): bool
    {
        $business = $this->target();

        return $business
            ? $this->user()->can('update', $business)
            : $this->user()->can('create', Business::class);
    }

    public function rules(): array
    {
        $creating = $this->target() === null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'is_active' => ['sometimes', 'boolean'],
            // Base currency is chosen once at creation; changing it later would invalidate history.
            'base_currency_code' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys(CurrencyCatalog::all()))],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'settings' => ['sometimes', 'array'],
            'settings.inventory.allow_negative_stock' => ['sometimes', 'boolean'],
            'settings.inventory.costing_method' => ['sometimes', Rule::in(['average', 'fifo'])],
            'settings.sales.invoice_prefix' => ['sometimes', 'string', 'max:10', 'alpha_dash'],
            'settings.sales.receipt_width' => ['sometimes', Rule::in(['58', '80'])],
            'settings.sales.invoice_footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.sales.require_open_register' => ['sometimes', 'boolean'],
            'settings.sales.invoice_terms' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** Flatten nested settings to the dotted keys SettingsService expects. */
    public function settingValues(): array
    {
        $flat = [];
        foreach ((array) $this->validated('settings', []) as $group => $items) {
            foreach ((array) $items as $key => $value) {
                $flat["$group.$key"] = $value ?? '';
            }
        }

        return array_intersect_key($flat, SettingsService::DEFAULTS);
    }
}
