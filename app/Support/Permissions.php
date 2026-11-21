<?php

namespace App\Support;

/**
 * Single source of truth for roles and permissions.
 * Permissions for later phases are registered now so roles are stable from day one.
 */
class Permissions
{
    public const SUPER_ADMIN = 'Super Admin';
    public const BUSINESS_OWNER = 'Business Owner';
    public const ADMIN = 'Admin';
    public const BRANCH_MANAGER = 'Branch Manager';
    public const CASHIER = 'Cashier';
    public const ACCOUNTANT = 'Accountant';
    public const INVENTORY_MANAGER = 'Inventory Manager';
    public const STOREKEEPER = 'Storekeeper';
    public const SALES_STAFF = 'Sales Staff';

    /** Roles that automatically see every branch of the business they belong to. */
    public const ALL_BRANCH_ROLES = [self::SUPER_ADMIN, self::BUSINESS_OWNER, self::ADMIN];

    /** Roles that cannot be edited or deleted from the UI. */
    public const SYSTEM_ROLES = [
        self::SUPER_ADMIN, self::BUSINESS_OWNER, self::ADMIN, self::BRANCH_MANAGER, self::CASHIER,
        self::ACCOUNTANT, self::INVENTORY_MANAGER, self::STOREKEEPER, self::SALES_STAFF,
    ];

    /** @return array<string, string[]> permission groups for the role editor */
    public static function groups(): array
    {
        return [
            'Dashboard' => ['dashboard.view'],
            'Products' => ['products.view', 'products.create', 'products.update', 'products.delete', 'categories.manage', 'brands.manage', 'units.manage'],
            'Inventory' => ['inventory.view', 'inventory.adjust', 'inventory.transfer', 'warehouses.manage'],
            'Sales' => ['sales.view', 'sales.create', 'sales.update', 'sales.cancel', 'sales.return', 'sales.override_price', 'sales.discount'],
            'Purchases' => ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.receive', 'purchases.return'],
            'Contacts' => ['customers.manage', 'suppliers.manage'],
            'Finance' => ['expenses.manage', 'expenses.approve', 'cash_register.manage', 'accounting.view', 'accounting.manage', 'taxes.manage'],
            'Reports' => ['reports.view', 'reports.export'],
            'Administration' => ['users.manage', 'roles.manage', 'settings.manage', 'branches.manage', 'currencies.manage', 'businesses.manage', 'audit.view'],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /** @return array<string, string[]> */
    public static function rolePermissions(): array
    {
        $all = self::all();
        $withoutPlatform = array_values(array_diff($all, ['businesses.manage']));

        return [
            self::SUPER_ADMIN => $all,
            self::BUSINESS_OWNER => $withoutPlatform,
            self::ADMIN => array_values(array_diff($withoutPlatform, ['roles.manage'])),
            self::BRANCH_MANAGER => [
                'dashboard.view', 'products.view', 'inventory.view', 'inventory.adjust', 'inventory.transfer',
                'sales.view', 'sales.create', 'sales.update', 'sales.cancel', 'sales.return', 'sales.override_price', 'sales.discount',
                'purchases.view', 'purchases.create', 'purchases.receive', 'customers.manage', 'suppliers.manage',
                'expenses.manage', 'cash_register.manage', 'reports.view', 'reports.export',
            ],
            self::CASHIER => ['dashboard.view', 'products.view', 'sales.view', 'sales.create', 'customers.manage', 'cash_register.manage'],
            self::ACCOUNTANT => [
                'dashboard.view', 'sales.view', 'purchases.view', 'expenses.manage', 'expenses.approve',
                'accounting.view', 'accounting.manage', 'taxes.manage', 'reports.view', 'reports.export', 'customers.manage', 'suppliers.manage',
            ],
            self::INVENTORY_MANAGER => [
                'dashboard.view', 'products.view', 'products.create', 'products.update', 'categories.manage', 'brands.manage', 'units.manage',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'warehouses.manage',
                'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.return', 'suppliers.manage', 'reports.view',
            ],
            self::STOREKEEPER => ['dashboard.view', 'products.view', 'inventory.view', 'inventory.adjust', 'inventory.transfer', 'purchases.view', 'purchases.receive'],
            self::SALES_STAFF => ['dashboard.view', 'products.view', 'sales.view', 'sales.create', 'sales.discount', 'customers.manage'],
        ];
    }
}
