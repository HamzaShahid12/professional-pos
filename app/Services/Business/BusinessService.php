<?php

namespace App\Services\Business;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Settings\SettingsService;
use App\Support\CurrencyCatalog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessService
{
    public function __construct(private TenantContext $tenant, private SettingsService $settings, private ChartOfAccounts $chart) {}

    /**
     * Create a business with its base currency, main branch and default settings.
     * The owner is attached as business owner with access to the main branch.
     */
    public function create(array $data, User $owner): Business
    {
        return DB::transaction(function () use ($data, $owner) {
            $code = strtoupper($data['base_currency_code'] ?? 'USD');
            $catalog = CurrencyCatalog::find($code) ?? ['name' => $code, 'symbol' => $code, 'decimals' => 2];

            $business = Business::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'legal_name' => $data['legal_name'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'base_currency_code' => $code,
                'is_active' => true,
            ]);

            $business->users()->attach($owner->id, ['is_owner' => true]);

            $this->tenant->runAs($business->id, function () use ($business, $code, $catalog, $owner) {
                Currency::create([
                    'code' => $code, 'name' => $catalog['name'], 'symbol' => $catalog['symbol'],
                    'decimal_places' => $catalog['decimals'], 'exchange_rate' => 1, 'is_base' => true, 'is_active' => true,
                ]);

                $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_main' => true, 'is_active' => true]);
                $branch->users()->syncWithoutDetaching([$owner->id]);

                // Phase 2 defaults: common units of measure and a default warehouse for the main branch.
                foreach ([['Piece', 'pcs', false], ['Box', 'box', false], ['Dozen', 'dz', false], ['Pair', 'pair', false],
                    ['Kilogram', 'kg', true], ['Gram', 'g', true], ['Litre', 'l', true], ['Metre', 'm', true]] as [$name, $short, $decimal]) {
                    Unit::create(['name' => $name, 'short_name' => $short, 'allow_decimal' => $decimal, 'is_active' => true]);
                }
                Warehouse::create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'WH-MAIN', 'is_default' => true, 'is_active' => true]);

                $this->settings->seedDefaults();
                $this->chart->ensure(); // standard chart of accounts, so the first sale never has to create it
                ActivityLog::record('business.created', $business, ['name' => $business->name], $business->id);
            });

            return $business;
        });
    }

    public function update(Business $business, array $data): Business
    {
        $business->fill(collect($data)->only([
            'name', 'legal_name', 'tax_number', 'email', 'phone', 'address', 'country', 'timezone', 'is_active',
        ])->all())->save();

        ActivityLog::record('business.updated', $business, [], $business->id);

        return $business;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $i = 1;
        while (Business::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
