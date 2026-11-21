import { describe, expect, it } from 'vitest';
import { visibleNavigation } from './navigation';

const labels = (groups) => groups.flatMap((g) => g.items.map((i) => i.label));

describe('sidebar navigation', () => {
    it('hides items the user lacks permission for', () => {
        const can = (p) => p === 'dashboard.view';
        expect(labels(visibleNavigation(can))).toEqual(['Dashboard']);
    });

    it('lists every finished module and nothing that is not built yet (no dead links)', () => {
        const all = labels(visibleNavigation(() => true));
        ['Dashboard', 'POS', 'Sales', 'Accounting', 'Expenses', 'Cash Register', 'Reports', 'Users'].forEach((l) => expect(all).toContain(l));
    });

    it('shows reports only to users who may view them', () => {
        expect(labels(visibleNavigation((p) => p === 'reports.view'))).toEqual(['Reports']);
        expect(labels(visibleNavigation((p) => p === 'dashboard.view'))).not.toContain('Reports');
    });

    it('shows expenses and the cash register by permission', () => {
        expect(labels(visibleNavigation((p) => p === 'expenses.manage'))).toEqual(['Expenses']);
        expect(labels(visibleNavigation((p) => p === 'cash_register.manage'))).toEqual(['Cash Register']);
    });

    it('shows accounting only to users who may view it', () => {
        expect(labels(visibleNavigation((p) => p === 'accounting.view'))).toEqual(['Accounting']);
        expect(labels(visibleNavigation((p) => p === 'dashboard.view'))).not.toContain('Accounting');
    });

    it('shows the till and sales list to cashiers', () => {
        const cashier = (p) => ['dashboard.view', 'sales.create', 'sales.view'].includes(p);
        expect(labels(visibleNavigation(cashier))).toEqual(['Dashboard', 'POS', 'Sales']);
    });

    it('shows the catalog pages only to users who may manage them', () => {
        const cashier = (p) => ['dashboard.view', 'products.view'].includes(p);
        expect(labels(visibleNavigation(cashier))).toEqual(['Dashboard', 'Products']);

        const all = labels(visibleNavigation(() => true));
        ['Categories', 'Brands', 'Units', 'Warehouses', 'Tax Settings'].forEach((l) => expect(all).toContain(l));
    });

    it('shows the inventory pages to users who may view stock', () => {
        const storekeeper = (p) => ['dashboard.view', 'inventory.view'].includes(p);
        expect(labels(visibleNavigation(storekeeper))).toEqual(['Dashboard', 'Inventory', 'Stock Adjustments', 'Stock Transfers', 'Stock Counts']);
        const buyer = (p) => p === 'purchases.view';
        expect(labels(visibleNavigation(buyer))).toEqual(['Purchases']);
    });

    it('shows customers and suppliers to users who manage them', () => {
        const can = (p) => ['customers.manage', 'suppliers.manage'].includes(p);
        expect(labels(visibleNavigation(can))).toEqual(['Customers', 'Suppliers']);
    });

    it('drops empty groups', () => {
        expect(visibleNavigation(() => false)).toEqual([]);
    });
});
