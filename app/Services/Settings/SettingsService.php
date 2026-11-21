<?php

namespace App\Services\Settings;

use App\Models\BusinessSetting;

class SettingsService
{
    /** Defaults documented in README. Costing and negative-stock settings drive Phase 3+ inventory logic. */
    public const DEFAULTS = [
        'inventory.allow_negative_stock' => false,
        'inventory.costing_method' => 'average', // average | fifo
        'sales.invoice_prefix' => 'INV',
        'sales.receipt_width' => '80',            // 58 | 80
        'sales.invoice_footer' => '',
        'sales.invoice_terms' => '',
        'sales.require_open_register' => false,   // cash sales need an open cash register session
        'accounting.lock_date' => '',             // postings dated on or before this day are refused
    ];

    /** All settings for the business in the current tenant context. */
    public function all(): array
    {
        $stored = BusinessSetting::query()->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function setMany(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            BusinessSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function seedDefaults(): void
    {
        $this->setMany(self::DEFAULTS);
    }
}
