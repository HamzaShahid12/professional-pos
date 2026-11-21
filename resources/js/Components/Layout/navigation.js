/**
 * Sidebar definition. `ready: false` entries belong to later phases and are hidden until their
 * pages exist, so there are never dead links. Flip `ready` when a phase ships.
 * `permission` only controls visibility; the backend enforces access independently.
 */
export const navigation = [
    {
        group: 'Overview',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', permission: 'dashboard.view', ready: true },
        ],
    },
    {
        group: 'Catalog',
        items: [
            { label: 'Products', href: '/products', icon: 'building', permission: 'products.view', ready: true },
            { label: 'Categories', href: '/categories', icon: 'branch', permission: 'categories.manage', ready: true },
            { label: 'Brands', href: '/brands', icon: 'shield', permission: 'brands.manage', ready: true },
            { label: 'Units', href: '/units', icon: 'settings', permission: 'units.manage', ready: true },
            { label: 'Warehouses', href: '/warehouses', icon: 'building', permission: 'warehouses.manage', ready: true },
        ],
    },
    {
        group: 'Sales & Stock',
        items: [
            { label: 'POS', href: '/pos', icon: 'trend', permission: 'sales.create', ready: true },
            { label: 'Inventory', href: '/inventory', icon: 'building', permission: 'inventory.view', ready: true },
            { label: 'Stock Adjustments', href: '/inventory/adjustments', icon: 'edit', permission: 'inventory.view', ready: true },
            { label: 'Stock Transfers', href: '/inventory/transfers', icon: 'branch', permission: 'inventory.view', ready: true },
            { label: 'Stock Counts', href: '/inventory/counts', icon: 'activity', permission: 'inventory.view', ready: true },
            { label: 'Sales', href: '/sales', icon: 'trend', permission: 'sales.view', ready: true },
            { label: 'Purchases', href: '/purchases', icon: 'trend', permission: 'purchases.view', ready: true },
            { label: 'Customers', href: '/customers', icon: 'users', permission: 'customers.manage', ready: true },
            { label: 'Suppliers', href: '/suppliers', icon: 'users', permission: 'suppliers.manage', ready: true },
        ],
    },
    {
        group: 'Finance',
        items: [
            { label: 'Expenses', href: '/expenses', icon: 'currency', permission: 'expenses.manage', ready: true },
            { label: 'Cash Register', href: '/cash-register', icon: 'currency', permission: 'cash_register.manage', ready: true },
            { label: 'Accounting', href: '/accounting', icon: 'currency', permission: 'accounting.view', ready: true },
            { label: 'Reports', href: '/reports', icon: 'activity', permission: 'reports.view', ready: true },
        ],
    },
    {
        group: 'Administration',
        items: [
            { label: 'Users', href: '/users', icon: 'users', permission: 'users.manage', ready: true },
            { label: 'Roles & Permissions', href: '/roles', icon: 'shield', permission: 'roles.manage', ready: true },
            { label: 'Business Settings', href: '/settings/business', icon: 'settings', permission: 'settings.manage', ready: true },
            { label: 'Branch Settings', href: '/settings/branches', icon: 'branch', permission: 'branches.manage', ready: true },
            { label: 'Currency Settings', href: '/settings/currencies', icon: 'currency', permission: 'currencies.manage', ready: true },
            { label: 'Tax Settings', href: '/settings/taxes', icon: 'currency', permission: 'taxes.manage', ready: true },
            { label: 'Activity Logs', href: '/activity-logs', icon: 'activity', permission: 'audit.view', ready: true },
            { label: 'Businesses', href: '/businesses', icon: 'building', permission: 'businesses.manage', ready: true },
        ],
    },
];

/** Visible groups for a permission checker. */
export function visibleNavigation(can) {
    return navigation
        .map((g) => ({ ...g, items: g.items.filter((i) => i.ready && can(i.permission)) }))
        .filter((g) => g.items.length > 0);
}
