<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Support\TenantContext;
use LogicException;

/**
 * The standard chart every business starts with. Automatic postings find accounts by `system_key`, so the
 * owner may renumber or rename them freely. A missing system account is created on first use, which also
 * covers businesses that existed before accounting was installed.
 */
class ChartOfAccounts
{
    /** system_key => [code, name, type] */
    public const DEFAULTS = [
        'cash' => ['1000', 'Cash in Hand', 'asset'],
        'bank' => ['1010', 'Bank & Cards', 'asset'],
        'receivable' => ['1100', 'Accounts Receivable', 'asset'],
        'inventory' => ['1200', 'Inventory', 'asset'],
        'tax_input' => ['1300', 'Input Tax Recoverable', 'asset'],
        'payable' => ['2000', 'Accounts Payable', 'liability'],
        'tax_output' => ['2100', 'Sales Tax Payable', 'liability'],
        'equity' => ['3000', "Owner's Equity", 'equity'],
        'opening_equity' => ['3100', 'Opening Balance Equity', 'equity'],
        'sales' => ['4000', 'Sales Revenue', 'revenue'],
        'sales_returns' => ['4100', 'Sales Returns', 'revenue'],
        'cogs' => ['5000', 'Cost of Goods Sold', 'expense'],
        'inventory_adjustment' => ['5100', 'Inventory Adjustments', 'expense'],
        'expense_general' => ['6000', 'Operating Expenses', 'expense'],
        'expense_rent' => ['6100', 'Rent', 'expense'],
        'expense_salaries' => ['6200', 'Salaries & Wages', 'expense'],
        'expense_utilities' => ['6300', 'Utilities', 'expense'],
        'expense_marketing' => ['6400', 'Marketing & Advertising', 'expense'],
        'ledger_adjustment' => ['6900', 'Ledger Adjustments', 'expense'],
        'cash_difference' => ['6950', 'Cash Over/Short', 'expense'],
    ];

    /** @var array<int, array<string,int>> */
    private array $cache = [];

    public function __construct(private TenantContext $tenant) {}

    public function id(string $key): int
    {
        if (! isset(self::DEFAULTS[$key])) {
            throw new LogicException("Unknown system account '$key'.");
        }

        $businessId = $this->tenant->businessId() ?? throw new LogicException('Accounting needs a business context.');
        $map = $this->cache[$businessId] ??= Account::query()->whereNotNull('system_key')->pluck('id', 'system_key')->all();

        if (! isset($map[$key])) {
            $this->ensure();
            $map = $this->cache[$businessId] = Account::query()->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        }

        return $map[$key];
    }

    /** Create every missing system account. Safe to call repeatedly. */
    public function ensure(): void
    {
        $existing = Account::query()->whereNotNull('system_key')->pluck('system_key')->all();
        $taken = Account::query()->pluck('code')->all();

        foreach (self::DEFAULTS as $key => [$code, $name, $type]) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            // If the owner already used this code for another account, move ours to the next free one.
            while (in_array($code, $taken, true)) {
                $code = (string) ((int) $code + 1);
            }
            $taken[] = $code;
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'system_key' => $key, 'is_system' => true, 'is_active' => true]);
        }

        $this->cache = [];
    }
}
