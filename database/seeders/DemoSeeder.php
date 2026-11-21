<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Services\Business\BusinessService;
use App\Services\CurrencyService;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/** Demo data: one business (base PKR), two branches, extra currencies and one user per role. Password: "password". */
class DemoSeeder extends Seeder
{
    public function run(BusinessService $businesses, CurrencyService $currencies, TenantContext $tenant): void
    {
        $superAdmin = User::create(['name' => 'Super Admin', 'email' => 'admin@pos.test', 'password' => 'password']);
        $superAdmin->assignRole(Permissions::SUPER_ADMIN);

        $owner = User::create(['name' => 'Business Owner', 'email' => 'owner@pos.test', 'password' => 'password']);
        $owner->assignRole(Permissions::BUSINESS_OWNER);

        $business = $businesses->create([
            'name' => 'Demo Retail Store', 'country' => 'Pakistan', 'timezone' => 'Asia/Karachi', 'base_currency_code' => 'PKR',
            'email' => 'info@demo-store.test', 'phone' => '+92 300 0000000', 'address' => 'Main Boulevard, Lahore',
        ], $owner);

        $tenant->runAs($business->id, function () use ($business, $currencies, $owner, $superAdmin) {
            $main = Branch::where('is_main', true)->firstOrFail();
            $downtown = Branch::create(['name' => 'Downtown Branch', 'code' => 'DT', 'address' => 'Mall Road, Lahore', 'is_active' => true]);
            $downtown->users()->sync([$owner->id]);

            $business->users()->syncWithoutDetaching([$superAdmin->id => ['is_owner' => false]]);

            foreach ([['USD', 'US Dollar', '$', 280], ['EUR', 'Euro', '€', 305], ['GBP', 'British Pound', '£', 355]] as [$code, $name, $symbol, $rate]) {
                $c = \App\Models\Currency::create(['code' => $code, 'name' => $name, 'symbol' => $symbol, 'decimal_places' => 2, 'exchange_rate' => 1, 'is_base' => false, 'is_active' => true]);
                $currencies->setRate($c, (string) $rate, null, $owner, 'initial');
            }

            $this->seedCatalog($owner);

            $staff = [
                ['Admin User', 'admin.user@pos.test', Permissions::ADMIN, [$main->id, $downtown->id]],
                ['Branch Manager', 'manager@pos.test', Permissions::BRANCH_MANAGER, [$main->id]],
                ['Cashier', 'cashier@pos.test', Permissions::CASHIER, [$main->id]],
                ['Accountant', 'accountant@pos.test', Permissions::ACCOUNTANT, [$main->id]],
                ['Inventory Manager', 'inventory@pos.test', Permissions::INVENTORY_MANAGER, [$main->id, $downtown->id]],
                ['Storekeeper', 'storekeeper@pos.test', Permissions::STOREKEEPER, [$main->id]],
                ['Sales Staff', 'sales@pos.test', Permissions::SALES_STAFF, [$downtown->id]],
            ];
            foreach ($staff as [$name, $email, $role, $branchIds]) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'current_business_id' => $business->id]);
                $user->assignRole($role);
                $user->businesses()->attach($business->id, ['is_owner' => false]);
                $user->branches()->sync($branchIds);
            }
        });
    }

    /** Phase 2 demo catalog (runs inside the demo business context). */
    private function seedCatalog(User $owner): void
    {
        $master = app(\App\Services\Catalog\MasterDataService::class);
        $products = app(\App\Services\Catalog\ProductService::class);

        $gst = $master->saveTax(['name' => 'GST 18%', 'rate' => '18', 'is_inclusive' => false, 'is_active' => true]);
        $master->saveTax(['name' => 'Service Tax 5% (inclusive)', 'rate' => '5', 'is_inclusive' => true, 'is_active' => true]);

        $cats = [];
        foreach (['Beverages', 'Snacks', 'Household', 'Electronics', 'Clothing'] as $name) {
            $cats[$name] = $master->saveCategory(['name' => $name, 'is_active' => true])->id;
        }
        $brands = [];
        foreach (['Acme Foods', 'FreshCo', 'HomeEase', 'VoltTech', 'UrbanWear'] as $name) {
            $brands[$name] = $master->saveBrand(['name' => $name, 'is_active' => true])->id;
        }
        $pcs = \App\Models\Unit::where('short_name', 'pcs')->value('id');
        $kg = \App\Models\Unit::where('short_name', 'kg')->value('id');

        $items = [
            ['Mineral Water 1.5L', 'BEV-001', '8901000000011', 'Beverages', 'FreshCo', $pcs, '60', '90', '80', '75', 48, []],
            ['Orange Juice 1L', 'BEV-002', '8901000000028', 'Beverages', 'FreshCo', $pcs, '180', '260', '240', '230', 24, [$gst->id]],
            ['Potato Chips 100g', 'SNK-001', '8901000000035', 'Snacks', 'Acme Foods', $pcs, '70', '110', '100', '95', 40, [$gst->id]],
            ['Salted Peanuts (loose)', 'SNK-002', '8901000000042', 'Snacks', 'Acme Foods', $kg, '900', '1250', '1150', '1100', 10, []],
            ['Dishwashing Liquid 500ml', 'HOU-001', '8901000000059', 'Household', 'HomeEase', $pcs, '210', '320', '300', '280', 20, [$gst->id]],
            ['LED Bulb 12W', 'ELE-001', '8901000000066', 'Electronics', 'VoltTech', $pcs, '250', '420', '390', '350', 30, [$gst->id]],
            ['USB-C Cable 1m', 'ELE-002', '8901000000073', 'Electronics', 'VoltTech', $pcs, '300', '550', '500', '450', 25, [$gst->id]],
        ];
        foreach ($items as [$name, $sku, $barcode, $cat, $brand, $unit, $cost, $price, $wholesale, $min, $minStock, $taxIds]) {
            $products->create([
                'name' => $name, 'sku' => $sku, 'barcode' => $barcode, 'category_id' => $cats[$cat], 'brand_id' => $brands[$brand],
                'unit_id' => $unit, 'purchase_price' => $cost, 'selling_price' => $price, 'wholesale_price' => $wholesale,
                'min_selling_price' => $min, 'min_stock' => (string) $minStock, 'is_active' => true, 'track_inventory' => true,
                'tax_ids' => $taxIds,
            ], [], $owner);
        }

        // A product with variants.
        $products->create([
            'name' => 'Classic T-Shirt', 'sku' => 'CLO-TEE', 'category_id' => $cats['Clothing'], 'brand_id' => $brands['UrbanWear'],
            'unit_id' => $pcs, 'purchase_price' => '450', 'selling_price' => '900', 'min_stock' => '10', 'is_active' => true,
            'track_inventory' => true, 'tax_ids' => [$gst->id],
            'variants' => [
                ['name' => 'Black / M', 'sku' => 'CLO-TEE-BM', 'barcode' => '8901000000102', 'is_active' => true],
                ['name' => 'Black / L', 'sku' => 'CLO-TEE-BL', 'barcode' => '8901000000119', 'is_active' => true],
                ['name' => 'White / M', 'sku' => 'CLO-TEE-WM', 'barcode' => '8901000000126', 'selling_price' => '950', 'is_active' => true],
            ],
        ], [], $owner);
    }
}
