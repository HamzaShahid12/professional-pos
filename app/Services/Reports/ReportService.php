<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Support\InventoryAccess;
use App\Support\Rounding;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Read-only business reports. Every report returns the same shape so one page and one CSV exporter serve them all:
 *
 *  title, columns [{key,label,type}], rows [assoc], totals (assoc|null), chart ({type,labels,datasets}|null)
 *
 * Money and quantities are decimal strings (4 dp); only chart data are floats, because a chart is a picture.
 * Everything is limited to the branches (and so warehouses) the user works in.
 */
class ReportService
{
    /** key => [title, group, description, takes dates, takes branch] */
    public const TYPES = [
        'sales_daily' => ['Sales by day', 'Sales', 'Invoices, net sales, tax, cost and profit for each day.', true, true],
        'sales_products' => ['Sales by product', 'Sales', 'Quantity, revenue, cost and margin for every product sold.', true, true],
        'sales_payments' => ['Sales by payment method', 'Sales', 'How customers paid, and how much was sold on credit.', true, true],
        'sales_cashiers' => ['Sales by cashier', 'Sales', 'Invoices and takings for each person who sold.', true, true],
        'purchases_suppliers' => ['Purchases by supplier', 'Purchasing', 'Goods received from each supplier.', true, true],
        'stock_valuation' => ['Stock valuation', 'Inventory', 'Quantity and value on hand for each product in each warehouse, right now.', false, false],
        'stock_low' => ['Low and out of stock', 'Inventory', 'Products at or below their minimum stock level, right now.', false, false],
        'customer_balances' => ['Customer balances', 'Receivables and payables', 'Customers who owe you, or hold an advance, right now.', false, false],
        'supplier_balances' => ['Supplier balances', 'Receivables and payables', 'Suppliers you owe, or who hold your advance, right now.', false, false],
        'expenses_categories' => ['Expenses by category', 'Finance', 'Approved expenses grouped by category.', true, true],
    ];

    public const MAX_DAYS = 400;

    public function __construct(private TenantContext $tenant, private InventoryAccess $access) {}

    public function tz(): string
    {
        return $this->tenant->business()?->timezone ?? config('app.timezone');
    }

    public function today(): string
    {
        return now($this->tz())->toDateString();
    }

    /** @return array{title:string, columns:array, rows:array, totals:?array, chart:?array, total_rows:int, truncated:bool} */
    public function run(string $type, User $user, array $filters = [], ?int $limit = null): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown report '$type'.");
        }

        $from = $filters['from'] ?? substr($this->today(), 0, 8).'01';
        $to = $filters['to'] ?? $this->today();
        $branches = $this->branchIds($user, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);

        $report = match ($type) {
            'sales_daily' => $this->salesDaily($from, $to, $branches),
            'sales_products' => $this->salesProducts($from, $to, $branches),
            'sales_payments' => $this->salesPayments($from, $to, $branches),
            'sales_cashiers' => $this->salesCashiers($from, $to, $branches),
            'purchases_suppliers' => $this->purchasesSuppliers($from, $to, $branches),
            'stock_valuation' => $this->stockValuation($user),
            'stock_low' => $this->stockLow($user),
            'customer_balances' => $this->balances(Customer::class, 'Customer', true),
            'supplier_balances' => $this->balances(Supplier::class, 'Supplier', false),
            'expenses_categories' => $this->expensesCategories($from, $to, $branches),
        };

        $report['title'] = self::TYPES[$type][0];
        $report['total_rows'] = count($report['rows']);
        $report['truncated'] = $limit !== null && $report['total_rows'] > $limit;
        if ($report['truncated']) {
            $report['rows'] = array_slice($report['rows'], 0, $limit);
        }

        return $report;
    }

    /** Branches the user may report on, narrowed to one when asked. */
    public function branchIds(User $user, ?int $requested = null): array
    {
        $ids = $user->accessibleBranchIds($this->tenant->businessId())->all();

        return $requested ? array_values(array_intersect($ids, [$requested])) : $ids;
    }

    // ---- sales -------------------------------------------------------------------------------------

    private function salesDaily(string $from, string $to, array $branches): array
    {
        $days = [];
        foreach ($this->dateRange($from, $to) as $day) {
            $days[$day] = ['date' => $day, 'invoices' => 0, 'net' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'tax' => BigDecimal::zero(),
                'total' => BigDecimal::zero(), 'cogs' => BigDecimal::zero(), 'returns' => BigDecimal::zero()];
        }

        $sales = Sale::query()->where('status', 'completed')->soldBetween($from, $to)->whereIn('branch_id', $branches)
            ->select(['id', 'sold_at', 'subtotal', 'discount_total', 'tax_total', 'total', 'cogs_total'])->cursor();
        foreach ($sales as $s) {
            $day = $s->sold_at->copy()->setTimezone($this->tz())->toDateString();
            if (! isset($days[$day])) {
                continue; // a boundary second that rounds outside the range
            }
            $days[$day]['invoices']++;
            $days[$day]['net'] = $days[$day]['net']->plus($s->subtotal);
            $days[$day]['discount'] = $days[$day]['discount']->plus($s->discount_total);
            $days[$day]['tax'] = $days[$day]['tax']->plus($s->tax_total);
            $days[$day]['total'] = $days[$day]['total']->plus($s->total);
            $days[$day]['cogs'] = $days[$day]['cogs']->plus($s->cogs_total);
        }

        $returns = SaleReturn::query()->join('sales', 'sales.id', '=', 'sale_returns.sale_id')->whereIn('sales.branch_id', $branches)
            ->whereBetween('sale_returns.return_date', [$from, $to])
            ->selectRaw('sale_returns.return_date AS d, SUM(sale_returns.refund_total) AS refunded')->groupBy('sale_returns.return_date')->get();
        foreach ($returns as $r) {
            $day = substr((string) $r->d, 0, 10);
            if (isset($days[$day])) {
                $days[$day]['returns'] = BigDecimal::of((string) $r->refunded);
            }
        }

        $rows = [];
        $sum = ['invoices' => 0, 'net' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'total' => BigDecimal::zero(),
            'cogs' => BigDecimal::zero(), 'profit' => BigDecimal::zero(), 'returns' => BigDecimal::zero()];
        $labels = [];
        $series = [];
        foreach ($days as $d) {
            $profit = $d['net']->minus($d['cogs']);
            $labels[] = $d['date'];
            $series[] = (float) (string) $d['net'];
            if ($d['invoices'] === 0 && $d['returns']->isZero()) {
                continue;
            }
            $rows[] = ['date' => $d['date'], 'invoices' => $d['invoices'], 'net' => $this->m($d['net']), 'discount' => $this->m($d['discount']), 'tax' => $this->m($d['tax']),
                'total' => $this->m($d['total']), 'cogs' => $this->m($d['cogs']), 'profit' => $this->m($profit), 'returns' => $this->m($d['returns'])];
            $sum['invoices'] += $d['invoices'];
            foreach (['net', 'discount', 'tax', 'total', 'cogs', 'returns'] as $k) {
                $sum[$k] = $sum[$k]->plus($d[$k]);
            }
            $sum['profit'] = $sum['profit']->plus($profit);
        }

        return [
            'columns' => [
                $this->col('date', 'Date', 'date'), $this->col('invoices', 'Invoices', 'int'), $this->col('net', 'Net sales', 'money'), $this->col('discount', 'Discounts', 'money'),
                $this->col('tax', 'Tax', 'money'), $this->col('total', 'Total', 'money'), $this->col('cogs', 'Cost of goods', 'money'),
                $this->col('profit', 'Gross profit', 'money'), $this->col('returns', 'Refunded', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['date' => 'Total', 'invoices' => $sum['invoices']] + array_map(fn ($v) => $this->m($v), array_diff_key($sum, ['invoices' => 1])),
            'chart' => ['type' => 'line', 'labels' => $labels, 'datasets' => [['label' => 'Net sales', 'data' => $series]]],
        ];
    }

    private function salesProducts(string $from, string $to, array $branches): array
    {
        $data = Sale::query()->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')->soldBetween($from, $to)->whereIn('sales.branch_id', $branches)
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id AS pid, MAX(sale_items.name) AS name, MAX(sale_items.sku) AS sku, SUM(sale_items.quantity) AS qty, SUM(sale_items.returned_qty) AS ret, '
                .'SUM(sale_items.net_amount) AS revenue, SUM(sale_items.cost_total) AS cost')
            ->get()->sortByDesc(fn ($r) => (float) $r->revenue)->values();

        $rows = [];
        $t = ['qty' => BigDecimal::zero(), 'ret' => BigDecimal::zero(), 'revenue' => BigDecimal::zero(), 'cost' => BigDecimal::zero()];
        foreach ($data as $r) {
            $profit = BigDecimal::of((string) $r->revenue)->minus((string) $r->cost);
            $rows[] = ['product' => $r->name, 'sku' => $r->sku, 'quantity' => $this->m($r->qty), 'returned' => $this->m($r->ret), 'revenue' => $this->m($r->revenue),
                'cost' => $this->m($r->cost), 'profit' => $this->m($profit), 'margin' => $this->pct($profit, BigDecimal::of((string) $r->revenue))];
            $t['qty'] = $t['qty']->plus((string) $r->qty);
            $t['ret'] = $t['ret']->plus((string) $r->ret);
            $t['revenue'] = $t['revenue']->plus((string) $r->revenue);
            $t['cost'] = $t['cost']->plus((string) $r->cost);
        }
        $profit = $t['revenue']->minus($t['cost']);
        $top = array_slice($rows, 0, 10);

        return [
            'columns' => [
                $this->col('product', 'Product', 'text'), $this->col('sku', 'SKU', 'text'), $this->col('quantity', 'Sold', 'qty'), $this->col('returned', 'Returned', 'qty'),
                $this->col('revenue', 'Net revenue', 'money'), $this->col('cost', 'Cost', 'money'), $this->col('profit', 'Profit', 'money'), $this->col('margin', 'Margin %', 'percent'),
            ],
            'rows' => $rows,
            'totals' => ['product' => 'Total', 'quantity' => $this->m($t['qty']), 'returned' => $this->m($t['ret']), 'revenue' => $this->m($t['revenue']), 'cost' => $this->m($t['cost']),
                'profit' => $this->m($profit), 'margin' => $this->pct($profit, $t['revenue'])],
            'chart' => $top ? ['type' => 'bar', 'labels' => array_column($top, 'product'), 'datasets' => [['label' => 'Net revenue (top 10)', 'data' => array_map(fn ($r) => (float) $r['revenue'], $top)]]] : null,
        ];
    }

    private function salesPayments(string $from, string $to, array $branches): array
    {
        $base = fn () => Sale::query()->where('sales.status', 'completed')->soldBetween($from, $to)->whereIn('sales.branch_id', $branches);

        $paid = $base()->join('sale_payments', 'sale_payments.sale_id', '=', 'sales.id')->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method AS method, COUNT(DISTINCT sales.id) AS invoices, SUM(sale_payments.amount) AS amount')->get();
        $credit = $base()->where('sales.balance_due', '>', 0)->selectRaw('COUNT(*) AS invoices, COALESCE(SUM(sales.balance_due), 0) AS amount')->first();

        $rows = [];
        $total = BigDecimal::zero();
        foreach ($paid->sortByDesc(fn ($r) => (float) $r->amount) as $r) {
            $rows[] = ['method' => ucfirst($r->method), 'invoices' => (int) $r->invoices, 'amount' => $this->m($r->amount)];
            $total = $total->plus((string) $r->amount);
        }
        if ((int) $credit->invoices > 0) {
            $rows[] = ['method' => 'On credit (not yet paid)', 'invoices' => (int) $credit->invoices, 'amount' => $this->m($credit->amount)];
            $total = $total->plus((string) $credit->amount);
        }

        return [
            'columns' => [$this->col('method', 'Method', 'text'), $this->col('invoices', 'Invoices', 'int'), $this->col('amount', 'Amount', 'money')],
            'rows' => $rows,
            'totals' => ['method' => 'Total', 'invoices' => '', 'amount' => $this->m($total)],
            'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($rows, 'method'), 'datasets' => [['label' => 'Amount', 'data' => array_map(fn ($r) => (float) $r['amount'], $rows)]]] : null,
        ];
    }

    private function salesCashiers(string $from, string $to, array $branches): array
    {
        $data = Sale::query()->leftJoin('users', 'users.id', '=', 'sales.created_by')
            ->where('sales.status', 'completed')->soldBetween($from, $to)->whereIn('sales.branch_id', $branches)
            ->groupBy('sales.created_by')
            ->selectRaw('sales.created_by AS uid, MAX(users.name) AS name, COUNT(*) AS invoices, SUM(sales.subtotal) AS net, SUM(sales.total) AS total, SUM(sales.discount_total) AS discount')
            ->get()->sortByDesc(fn ($r) => (float) $r->total)->values();

        $rows = [];
        $t = ['invoices' => 0, 'net' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
        foreach ($data as $r) {
            $rows[] = ['cashier' => $r->name ?? 'Deleted user', 'invoices' => (int) $r->invoices, 'net' => $this->m($r->net), 'discount' => $this->m($r->discount), 'total' => $this->m($r->total)];
            $t['invoices'] += (int) $r->invoices;
            foreach (['net', 'discount', 'total'] as $k) {
                $t[$k] = $t[$k]->plus((string) $r->$k);
            }
        }

        return [
            'columns' => [$this->col('cashier', 'Cashier', 'text'), $this->col('invoices', 'Invoices', 'int'), $this->col('net', 'Net sales', 'money'), $this->col('discount', 'Discounts given', 'money'), $this->col('total', 'Total', 'money')],
            'rows' => $rows,
            'totals' => ['cashier' => 'Total', 'invoices' => $t['invoices'], 'net' => $this->m($t['net']), 'discount' => $this->m($t['discount']), 'total' => $this->m($t['total'])],
            'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($rows, 'cashier'), 'datasets' => [['label' => 'Total', 'data' => array_map(fn ($r) => (float) $r['total'], $rows)]]] : null,
        ];
    }

    // ---- purchasing --------------------------------------------------------------------------------

    private function purchasesSuppliers(string $from, string $to, array $branches): array
    {
        $data = Purchase::query()->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'received')->whereBetween('purchases.received_at', [$from, $to])->whereIn('purchases.branch_id', $branches)
            ->groupBy('purchases.supplier_id')
            ->selectRaw('purchases.supplier_id AS sid, MAX(suppliers.name) AS name, COUNT(*) AS orders, SUM(purchases.total_base - purchases.tax_total * purchases.exchange_rate) AS net, '
                .'SUM(purchases.tax_total * purchases.exchange_rate) AS tax, SUM(purchases.total_base) AS total')
            ->get()->sortByDesc(fn ($r) => (float) $r->total)->values();

        $rows = [];
        $t = ['orders' => 0, 'net' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
        foreach ($data as $r) {
            $rows[] = ['supplier' => $r->name, 'orders' => (int) $r->orders, 'net' => $this->m($r->net), 'tax' => $this->m($r->tax), 'total' => $this->m($r->total)];
            $t['orders'] += (int) $r->orders;
            foreach (['net', 'tax', 'total'] as $k) {
                $t[$k] = $t[$k]->plus($this->m($r->$k));
            }
        }

        return [
            'columns' => [$this->col('supplier', 'Supplier', 'text'), $this->col('orders', 'Purchases', 'int'), $this->col('net', 'Net cost', 'money'), $this->col('tax', 'Tax', 'money'), $this->col('total', 'Total', 'money')],
            'rows' => $rows,
            'totals' => ['supplier' => 'Total', 'orders' => $t['orders'], 'net' => $this->m($t['net']), 'tax' => $this->m($t['tax']), 'total' => $this->m($t['total'])],
            'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($rows, 'supplier'), 'datasets' => [['label' => 'Total (base currency)', 'data' => array_map(fn ($r) => (float) $r['total'], $rows)]]] : null,
        ];
    }

    // ---- inventory ---------------------------------------------------------------------------------

    private function stockValuation(User $user): array
    {
        $data = Stock::query()->join('products', 'products.id', '=', 'stocks.product_id')->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stocks.variant_id')
            ->whereNull('products.deleted_at')->whereIn('stocks.warehouse_id', $this->access->warehouseIds($user))->where('stocks.quantity', '!=', 0)
            ->orderBy('warehouses.name')->orderBy('products.name')->orderBy('stocks.variant_key')
            ->get(['warehouses.name AS warehouse', 'products.name AS product', 'products.sku AS sku', 'product_variants.name AS variant', 'stocks.quantity AS qty', 'stocks.total_value AS value']);

        $rows = [];
        $qty = BigDecimal::zero();
        $value = BigDecimal::zero();
        $byWarehouse = [];
        foreach ($data as $r) {
            $q = BigDecimal::of((string) $r->qty);
            $v = BigDecimal::of((string) $r->value);
            $avg = $q->isZero() ? BigDecimal::zero() : $v->dividedBy($q, 4, Rounding::halfUp());
            $rows[] = ['warehouse' => $r->warehouse, 'product' => $r->variant ? $r->product.' · '.$r->variant : $r->product, 'sku' => $r->sku, 'quantity' => $this->m($q), 'avg_cost' => $this->m($avg), 'value' => $this->m($v)];
            $qty = $qty->plus($q);
            $value = $value->plus($v);
            $byWarehouse[$r->warehouse] = ($byWarehouse[$r->warehouse] ?? BigDecimal::zero())->plus($v);
        }

        return [
            'columns' => [$this->col('warehouse', 'Warehouse', 'text'), $this->col('product', 'Product', 'text'), $this->col('sku', 'SKU', 'text'), $this->col('quantity', 'On hand', 'qty'), $this->col('avg_cost', 'Average cost', 'money'), $this->col('value', 'Value', 'money')],
            'rows' => $rows,
            'totals' => ['warehouse' => 'Total', 'quantity' => $this->m($qty), 'value' => $this->m($value)],
            'chart' => $byWarehouse ? ['type' => 'bar', 'labels' => array_keys($byWarehouse), 'datasets' => [['label' => 'Stock value', 'data' => array_map(fn ($v) => (float) (string) $v, array_values($byWarehouse))]]] : null,
        ];
    }

    private function stockLow(User $user): array
    {
        $data = Stock::query()->join('products', 'products.id', '=', 'stocks.product_id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.track_inventory', true)
            ->whereIn('stocks.warehouse_id', $this->access->warehouseIds($user))
            ->groupBy('products.id')
            ->selectRaw('MAX(products.name) AS name, MAX(products.sku) AS sku, MAX(products.min_stock) AS min_stock, SUM(stocks.quantity) AS qty')
            ->havingRaw('SUM(stocks.quantity) <= 0 OR (MAX(products.min_stock) > 0 AND SUM(stocks.quantity) <= MAX(products.min_stock))')
            ->orderByRaw('SUM(stocks.quantity) ASC')->get();

        $rows = $data->map(fn ($r) => [
            'product' => $r->name, 'sku' => $r->sku, 'quantity' => $this->m($r->qty), 'min_stock' => $this->m($r->min_stock),
            'status' => BigDecimal::of((string) $r->qty)->isPositive() ? 'Low' : 'Out of stock',
        ])->all();

        return [
            'columns' => [$this->col('product', 'Product', 'text'), $this->col('sku', 'SKU', 'text'), $this->col('quantity', 'On hand', 'qty'), $this->col('min_stock', 'Minimum', 'qty'), $this->col('status', 'Status', 'text')],
            'rows' => $rows, 'totals' => null, 'chart' => null,
        ];
    }

    // ---- receivables, payables ---------------------------------------------------------------------

    private function balances(string $model, string $label, bool $withLimit): array
    {
        $parties = $model::query()->where('balance', '!=', 0)->orderByDesc('balance')->get();
        $owed = BigDecimal::zero();
        $advance = BigDecimal::zero();
        $rows = [];
        foreach ($parties as $p) {
            $b = BigDecimal::of($p->balance);
            if ($b->isPositive()) {
                $owed = $owed->plus($b);
            } else {
                $advance = $advance->plus($b);
            }
            $row = ['code' => $p->code, 'name' => $p->name, 'phone' => $p->phone, 'balance' => $this->m($b)];
            if ($withLimit) {
                $row['credit_limit'] = $this->m($p->credit_limit);
            }
            $rows[] = $row;
        }

        $columns = [$this->col('code', 'Code', 'text'), $this->col('name', $label, 'text'), $this->col('phone', 'Phone', 'text')];
        if ($withLimit) {
            $columns[] = $this->col('credit_limit', 'Credit limit', 'money');
        }
        $columns[] = $this->col('balance', $withLimit ? 'Owes you' : 'You owe', 'money');
        $top = array_slice($rows, 0, 10);

        return [
            'columns' => $columns, 'rows' => $rows,
            'totals' => ['name' => 'Total', 'balance' => $this->m($owed->plus($advance))],
            'chart' => $top ? ['type' => 'bar', 'labels' => array_column($top, 'name'), 'datasets' => [['label' => 'Balance (top 10)', 'data' => array_map(fn ($r) => (float) $r['balance'], $top)]]] : null,
            'notes' => ['Positive: '.($withLimit ? 'the customer owes you' : 'you owe the supplier').' '.$this->m($owed).'. Negative: advance held '.$this->m($advance->negated()).'.'],
        ];
    }

    // ---- finance -----------------------------------------------------------------------------------

    private function expensesCategories(string $from, string $to, array $branches): array
    {
        $data = Expense::query()->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expenses.status', 'approved')->whereBetween('expenses.expense_date', [$from, $to])->whereIn('expenses.branch_id', $branches)
            ->groupBy('expenses.category_id')
            ->selectRaw('MAX(expense_categories.name) AS name, COUNT(*) AS n, SUM(expenses.amount) AS amount')->get()->sortByDesc(fn ($r) => (float) $r->amount)->values();

        $rows = [];
        $n = 0;
        $total = BigDecimal::zero();
        foreach ($data as $r) {
            $rows[] = ['category' => $r->name, 'expenses' => (int) $r->n, 'amount' => $this->m($r->amount)];
            $n += (int) $r->n;
            $total = $total->plus((string) $r->amount);
        }

        return [
            'columns' => [$this->col('category', 'Category', 'text'), $this->col('expenses', 'Expenses', 'int'), $this->col('amount', 'Amount', 'money')],
            'rows' => $rows, 'totals' => ['category' => 'Total', 'expenses' => $n, 'amount' => $this->m($total)],
            'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($rows, 'category'), 'datasets' => [['label' => 'Amount', 'data' => array_map(fn ($r) => (float) $r['amount'], $rows)]]] : null,
        ];
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** @return Collection<int,string> */
    public function dateRange(string $from, string $to): Collection
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $days = collect();
        for ($d = $start; $d->lte($end) && $days->count() < self::MAX_DAYS; $d = $d->addDay()) {
            $days->push($d->toDateString());
        }

        return $days;
    }

    private function col(string $key, string $label, string $type): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private function m(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? 0))->toScale(4, Rounding::halfUp());
    }

    private function pct(BigDecimal $part, BigDecimal $whole): string
    {
        return $whole->isZero() ? '0.00' : (string) $part->multipliedBy(100)->dividedBy($whole, 2, Rounding::halfUp());
    }
}
