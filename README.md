# Professional POS — Retail POS, Inventory, Multi-Branch ERP & Accounting

Laravel 12 + Vue 3 (Inertia) + MySQL 8. One repository, one Laravel app, Vue lives in `resources/js`.

> **Status: Phases 1 (Foundation), 2 (Catalog), 3 (Inventory), 4 (Customers & suppliers), 5 (Purchases), 6 (POS & sales), 7 (Accounting), 8 (Expenses & cash register) 9 (Reports) and 10 (Finalization) written. All ten phases are written; none has been executed.**
> This folder is an **overlay**: it contains only the project's own files. Copy it over a fresh Laravel 12 install (steps below).

## Verification status (be aware)

| Check | Result |
|---|---|
| `php -l` on all 228 PHP files (app, routes, database, tests) | Passed |
| `npm run test` (Vitest, 16 tests) | Passed |
| `npm run build` (Vite production build) | Passed |
| `php artisan migrate` / `php artisan test` | **NOT RUN for Phases 1 to 10.** Packagist was unreachable where this was written, so Laravel could not be installed and no PHP test has ever executed. Expect a few failures on first run (typos, a wrong assumption about a package API). Run step 5, fix what it reports, and send me the output if you want me to fix it. Phases 2 to 9 were built on top of unverified earlier phases. The Phase 3 stock engine (costing, FIFO/FEFO layers, row locks) is the part most worth checking first: run `php artisan test --filter=InventoryTest` then `--filter=PartiesTest`, `--filter=PurchasesTest`, `--filter=SalesTest` `--filter=AccountingTest` `--filter=ExpensesCashTest` and `--filter=ReportsTest` and `--filter=Phase10Test`. |

## 1. Create the Laravel project (Windows + Laragon)

Laragon terminal (Menu → Terminal). Requires PHP 8.2+, Composer, Node 20+, MySQL 8.

```bat
cd D:\laragon\www
composer create-project laravel/laravel:^12.0 professional-pos
cd professional-pos
```

When the installer asks, choose **no starter kit**, **PHPUnit** (or Pest), **MySQL**.

## 2. Install packages

```bat
composer require inertiajs/inertia-laravel laravel/sanctum spatie/laravel-permission maatwebsite/excel barryvdh/laravel-dompdf
php artisan install:api
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
php artisan storage:link
```

`install:api` creates `routes/api.php` and the `personal_access_tokens` migration — **overwrite `routes/api.php` with this overlay's version afterwards.**
Laravel Excel is used from Phase 2 (product import/export); DomPDF produces the PDF invoice and Laravel Excel the xlsx report export (Phase 10). Neither could be run where this was written.

## 3. Copy this overlay into the project

Copy these from the overlay into `D:\laragon\www\professional-pos`, **replacing existing files**:

`app/`, `bootstrap/app.php`, `database/migrations/*` (add alongside the existing ones), `database/seeders/`, `resources/`, `routes/` (web.php, api.php), `tests/`, `package.json`, `vite.config.js`, `README.md`.

Do **not** keep Laravel's default `resources/css/app.css`, `resources/js/app.js`/`bootstrap.js`, `resources/views/welcome.blade.php`, `app/Models/User.php` or `app/Providers/AppServiceProvider.php` — the overlay versions replace them. Delete `database/factories/UserFactory.php` references if the seeder or tests complain (this overlay creates users directly).

Laravel 12 skeleton's `config/auth.php` already uses `App\Models\User`. Spatie's `config/permission.php` needs no changes (global roles, no teams).

## 4. Environment

`.env`:

```ini
APP_NAME="Professional POS"
APP_URL=http://professional-pos.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=professional_pos
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
```

Create the database `professional_pos` (HeidiSQL/Laragon → Database), then:

```bat
npm install
php artisan migrate --seed
npm run dev
```

Open http://professional-pos.test (Laragon auto virtual host). Run `npm run dev` in a second terminal while developing, or `npm run build` once.

### Demo logins (local only, password `password`)

| Role | Email |
|---|---|
| Super Admin | admin@pos.test |
| Business Owner | owner@pos.test |
| Admin | admin.user@pos.test |
| Branch Manager | manager@pos.test |
| Cashier | cashier@pos.test |
| Accountant | accountant@pos.test |
| Inventory Manager | inventory@pos.test |
| Storekeeper | storekeeper@pos.test |
| Sales Staff | sales@pos.test |

Demo data is seeded only when `APP_ENV` is `local` or `testing`. Production seeding creates roles and permissions only.

## 5. Run the tests

Feature tests use Laravel's default in-memory SQLite (needs the `pdo_sqlite` extension, enabled by default in Laragon). Confirm `phpunit.xml` contains `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` (the Laravel 12 default).

```bat
php artisan test
npm run test
npm run build
```

Tests: `tests/Unit/TaxCalculatorTest.php` and `tests/Feature/*` (Phase 2: `ProductCatalogTest`, `MasterDataTest`, `ProductImportExportTest`). Image tests need the PHP `gd` extension (on in Laragon). Phase 1 coverage: authentication & lockout, API tokens, password reset, business isolation, branch isolation, role/permission enforcement, currency conversion & immutable rate history, settings, dashboard scoping, audit logs.

**If a test fails, fix it before moving on** — these tests were written without being executed.

## 6. What Phase 1 contains

### Architecture decisions
- **Inertia + Vue 3** for the authenticated app (session auth, CSRF, no duplicate API layer); **`/api/v1`** with Sanctum tokens for integrations. Both call the same Form Requests and Services.
- **Shared Form Requests** live in `app/Http/Requests` (not duplicated under `Api/V1`) because web and API validate identically. API-only classes (Resources, controllers) are under `Api/V1`.
- **Tenancy:** `TenantContext` singleton is filled by the `tenant` middleware only after verifying membership. Business-owned models use `BelongsToBusiness` + `BusinessScope`, which **fails closed** (no context ⇒ zero rows) and blocks cross-business writes. Route-model binding is automatically scoped, so another business's id returns 404.
- **Branch access:** Super Admin / Business Owner / Admin see all branches of their business; every other role only sees branches assigned in `branch_user`. Forged `X-Business-Id` / `X-Branch-Id` headers are ignored unless the user is entitled to them.
- **Roles are global** (Spatie, no teams): a role's permissions are the same in every business. Per-business role customisation is a possible later extension.
- **Money:** `decimal` columns; all arithmetic via `brick/math` (ships with Laravel); amounts travel as strings, never floats.
- **Currency rate convention:** `rate` = base-currency units per 1 unit of the currency. History table is append-only (model refuses update/delete). Base currency is chosen at business creation and can't change.
- **Sidebar** only shows modules that exist. Later-phase entries are defined in `navigation.js` with `ready: false`; flip them as phases ship.

### Database (Phase 1)
| Table | Purpose |
|---|---|
| `businesses` | Tenant root; base currency code, timezone, logo |
| `branches` | Per business; unique `(business_id, code)`; one main branch; soft deletes |
| `business_user` | User ↔ business membership, `is_owner` |
| `branch_user` | User ↔ branch assignment |
| `users` (altered) | + phone, avatar, `is_active`, last login, current business/branch |
| `currencies` | Per business; unique `(business_id, code)`; `is_base`; current rate |
| `exchange_rates` | Immutable rate history with effective time and creator |
| `business_settings` | Key/JSON per business (costing method, negative stock, invoice prefix, receipt width…) |
| `activity_logs` | Audit trail (business, user, action, subject, IP) |
| Spatie tables | roles, permissions and pivots (from `vendor:publish`) |

### Features
Login/logout, forgot/reset password, change password, profile + avatar, active-session list and "sign out other devices", login throttling (5 attempts/min per email+IP), account deactivation (enforced on live sessions and tokens), audit logging; business settings; branch CRUD with staff assignment; currency management with rate history; user management scoped to the current business; role/permission editor (system roles protected, Super Admin locked); platform-level business creation (Super Admin); business/branch switcher in the header; reusable Vue components (BaseButton/Input/Select/Modal/Table/Pagination/Card/Badge, ConfirmDialog, SearchInput, DateRangePicker, CurrencyInput, MoneyDisplay, LoadingSpinner, EmptyState, AppSidebar/AppHeader), Pinia UI store, toasts, `usePermissions`/`useMoney`/`useConfirm` composables.

### API (v1) — Phase 1 endpoints
`POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`,
`GET|POST /businesses`, `GET|PUT /businesses/{id}`,
`GET|POST /branches`, `GET|PUT /branches/{id}`,
`GET|POST /currencies`, `PUT /currencies/{id}`, `POST /currencies/{id}/rates`.

Send `Authorization: Bearer <token>`; optionally `X-Business-Id` / `X-Branch-Id`. Errors are JSON `{ "message": "...", "errors": { field: [..] } }`.

## 6b. What Phase 2 contains (Catalog & master data)

### Database (Phase 2)
| Table | Purpose |
|---|---|
| `taxes` | Name, rate (0–100, 4 dp), inclusive/exclusive, soft deletes. `tax_account_id` is a plain column; Phase 7 adds the FK to the chart of accounts |
| `categories` | Self-referencing tree (`parent_id`), per business |
| `brands`, `units` | Per business; unique name / short name; units flag `allow_decimal` (kg, litre…) |
| `warehouses` | Per business, optional branch, unique code, one default |
| `products` | SKU/barcode unique per business (archived rows keep them reserved), prices `decimal(18,4)`, min stock, flags, soft deletes |
| `product_taxes` | Product ↔ tax (multiple taxes per product) |
| `product_variants` | Name, own SKU/barcode, optional own prices (null = inherit) |
| `product_images` | Several images, one primary |
| `product_prices` | **Append-only** price history (type, old, new, who, why) |

New businesses are seeded with 8 units (pcs, box, dz, pair, kg, g, l, m) and a default warehouse on the main branch. Demo data adds taxes, categories, brands, 8 products (one with variants).

### Features
Categories (tree, cycle protection), brands, units, taxes (inclusive/exclusive, multiple per product), warehouses (default handling); product CRUD with variants, multiple images, taxes and tracking flags; archive instead of delete; search by name/SKU/exact barcode; filters; **price history** on every price change; **bulk price update** (percent / add / set, scoped to all/category/brand, never below minimum selling price); **Excel import** (all-or-nothing, per-row error report, updates by SKU, auto-creates categories/brands, recognises taxes by name), **export** (same layout as the import) and a template download; `GET /products/lookup?q=` JSON endpoint for the POS barcode scanner; central `TaxCalculator` (inclusive/exclusive/mixed, exact rounding) that the POS and purchases will call.

### API (v1) additions
`GET|POST /products`, `GET|PUT|DELETE /products/{id}` (DELETE archives), `GET|POST /categories`. Product list filters: `search`, `category_id`, `brand_id`, `active`, `per_page`. Money fields are strings. Uploads use multipart; update with `POST` + `_method=PUT` when sending files.

### Decisions
- **SKU/barcode** are unique across products *and* variants of a business, enforced by a validation rule plus DB unique indexes.
- **Product stock overview** is delivered with Phase 3 (inventory) because stock does not exist yet.
- Category/brand/unit/tax/warehouse **delete is blocked while in use** (deactivate instead); otherwise soft-deleted.
- Deleting is archive-only for products; archiving is blocked while stock is on hand (Phase 3).
- Prices are stored in the business **base currency**; per-currency pricing arrives with sales/purchases (stored rate snapshot per transaction).
- Imports are limited to 2,000 rows / 5 MB per file.

## 6c. What Phase 3 contains (Inventory)

### Database (Phase 3)
| Table | Notes |
|---|---|
| `stocks` | One row per warehouse/product/variant: `quantity` and `total_value` (book value). Unique on warehouse + product + `variant_key` (variant id or 0) |
| `stock_movements` | **Immutable ledger** (update/delete throw). Signed quantity and cost, balance after, reference to the source document. Outbound movements get one row per cost layer |
| `product_batches` | Cost layers: batch number, expiry, remaining quantity, unit cost |
| `stock_adjustments` / `_items`, `stock_transfers` / `_items`, `stock_counts` / `_items` | Documents with per-business numbers ADJ-/TRF-/CNT-000001 |
| `document_sequences` | Gap-free numbers per business and document type (allocated inside the document's transaction) |

### Rules
- `StockService` is the **only** writer of stock. Every change is one transaction with row locks; `stocks.total_value` always equals `SUM(stock_movements.total_cost)`, and emptying a stock row leaves exactly 0.00 value (rounding residue goes into the last movement).
- **Costing** (Business setting `inventory.costing_method`): weighted average (default) or FIFO. Expiry-tracked products always consume batches **first-expired-first-out**. Stock without a layer (received before switching to FIFO) is costed at average.
- **Negative stock** is refused unless `inventory.allow_negative_stock` is on; the part sold beyond stock is costed at the purchase price.
- Quantities: > 0, max 4 decimals, whole numbers unless the unit allows decimals. Products with `track_inventory` off have no stock. Variant products need a variant.
- **Adjustments**: reasons opening_stock/found (increase only), damaged/expired (decrease only), correction/other (either). Posted adjustments are immutable; fix with a new one.
- **Transfers** are single-step (out and in within one transaction) and carry cost and batch/expiry. Both warehouses must be ones the user can access.
- **Stock counts**: draft snapshot -> enter counts -> complete posts adjustment movements for the difference to the *live* system quantity. Blank lines are untouched. Gains on expiry products create an unbatched layer.
- **Access**: users only see and use warehouses of branches they are assigned to; owners/admins see all warehouses.
- Product archive is blocked while stock is on hand; inventory tracking/variants cannot be switched once a product has stock history; a warehouse with stock or history cannot be deleted.
- Alerts: *out of stock* = total <= 0; *low stock* = total > 0 and <= the product's minimum (when a minimum is set), summed over the warehouses you can see. A product never received has no stock record and is not listed.

### Pages and API
Inventory overview (value, low/out cards, filters), stock ledger, expiry report, adjustments, transfers, counts, dashboard stock alerts. API: `GET /api/v1/inventory`, `GET /api/v1/inventory/movements`, `POST /api/v1/inventory/adjustments`, `POST /api/v1/inventory/transfers` (permissions `inventory.view|adjust|transfer`). `GET /products/lookup?stockable=1` now returns variants, unit and expiry flags.

Tests: `tests/Feature/InventoryTest.php` (costing, FIFO/FEFO, rollback, negative stock, decimals, immutability, adjustments, transfers, counts, alerts, valuation, expiry, branch/business isolation, permissions, API, guards).

## 6d. What Phase 4 contains (Customers & suppliers)

### Database (Phase 4)
| Table | Notes |
|---|---|
| `customers` | Code (auto `CUS-000001` or your own), contact details, tax number, `credit_limit` (0 = cash only), `balance`, soft delete |
| `suppliers` | Same plus contact person and payment terms (days); auto code `SUP-000001` |
| `party_ledger_entries` | **Immutable** ledger per customer/supplier: signed amount, balance after, entry type, date, reference |
| `party_payments` | Receipts (RCP-) and supplier payments (PAY-), immutable, each posts one ledger entry |

### Rules
- `LedgerService` is the **only** writer of balances: it locks the party row, appends the entry and updates `balance` in one transaction, so `balance = SUM(ledger.amount)` always.
- Sign convention: positive balance = the **customer owes you** / **you owe the supplier**. Negative = advance (customer prepaid / you prepaid the supplier). Receipts and payments always reduce the balance; overpaying creates an advance.
- **Opening balance** is posted once at creation (signed) and cannot be edited later. Corrections are signed **ledger adjustments** with a mandatory reason; a zero amount is refused.
- A party with an outstanding balance (either direction) **cannot be archived**.
- Codes are unique per business (archived parties keep theirs reserved). Customers and suppliers of another business are 404.
- `creditAvailable()` (credit limit minus balance, never below 0) is ready for the POS in Phase 6 to enforce credit sales. Phases 5 and 6 will post purchase/invoice/return entries through the same service.
- Customers are business-wide (not per branch). Permissions: `customers.manage`, `suppliers.manage` (the existing roles already carry them).
- All amounts are in the business **base currency**; from Phase 7 every ledger entry also posts a journal entry (section 6g).

### Pages and API
Customers and Suppliers lists (search, status filter, totals receivable/payable/advances), detail page with statement (date range, brought-forward and closing balance), receive/pay, adjust balance, edit, archive; dashboard cards for receivable and payable. JSON pickers: `GET /customers/lookup?q=`, `GET /suppliers/lookup?q=`. API: `GET|POST /api/v1/customers|suppliers`, `GET|PUT .../{id}`, `POST .../{id}/payments`.

Tests: `tests/Feature/PartiesTest.php` (codes, opening balance, receipts, advances, supplier payments, adjustments, immutability, archive guard, statement, credit limit, isolation, permissions, web forms, API, dashboard totals).

## 6e. What Phase 5 contains (Purchases)

### Database (Phase 5)
`purchases` (per-business `PUR-000001`, supplier, warehouse, currency, exchange rate, status ordered/received/cancelled, totals in document and base currency), `purchase_items` (cost as entered, discount, net, tax, tax snapshot, batch/expiry, returned quantity and credit already issued), `purchase_returns` (`PRT-000001`, immutable) and `purchase_return_items`.

### Flow and rules
- **Order -> receive.** A purchase is first `ordered`: it holds no stock and no debt and can be edited or cancelled. *Receiving* (or "Save and receive") posts in one transaction: stock in (type `purchase_received`), the supplier ledger entry, and an optional payment made at receiving. Any failure (for example missing batch/expiry data) rolls back everything and the purchase stays `ordered`.
- **Received purchases are immutable.** Fix mistakes with a purchase return. Receiving is all-or-nothing (no partial deliveries yet).
- **Tax** is calculated server-side by the shared `TaxCalculator` (inclusive taxes are extracted, exclusive ones added). Purchase tax is treated as **recoverable**: it is part of what you owe the supplier but **not** part of the stock cost.
- **Multi-currency.** Choose any active currency. The rate is read when the goods are **received** and frozen on the document; stock cost and supplier ledger are posted in the base currency. Later rate changes never touch a received purchase.
- **Returns** reduce stock (type `purchase_return`, refused if the goods are no longer in stock) and credit the supplier with the price paid, tax included, for the returned quantity. The final return of a line credits the exact remainder so no rounding cents are left. You cannot return more than was bought.
- **Payments.** `paid_amount` at receiving creates a normal supplier payment (`PAY-`). Later payments use the supplier screen; payments are not allocated to individual purchases yet (the supplier statement shows the running balance).
- Access: you only see and buy into warehouses of your branches. Permissions: `purchases.view`, `.create`, `.update` (edit/cancel an order), `.receive`, `.return`.
- Landed cost (freight, customs) and partial receipts are not included yet.

### Pages and API
Purchases list (filters), create/edit form (shared product lines editor with cost, discount, batch/expiry), detail page with receive and return dialogs. API: `GET|POST /api/v1/purchases` (send `receive_now: true` to receive in the same call), `GET /api/v1/purchases/{id}`, `POST /api/v1/purchases/{id}/receive`.

Tests: `tests/Feature/PurchasesTest.php` (totals, inclusive/exclusive tax, receiving, immutability, payment at receiving, foreign currency and rate freezing, edit/cancel, rollback, returns and exact remainder, return after sale, isolation, permissions, branch warehouses, web and API).

## 6f. What Phase 6 contains (POS & sales)

### Database (Phase 6)
`sales`, `sale_items` (name/sku/tax snapshots, cost and returned quantities), `sale_payments` (immutable), `sale_returns` and `sale_return_items` (immutable).

### Rules
- Prices come from the server. A different `unit_price` needs `sales.override_price`, a discount needs `sales.discount`, and the product's minimum selling price is always enforced.
- Totals, tax, change and credit are computed server-side; the POS previews them via `POST /pos/quote`.
- Completion is one transaction (3 deadlock retries): stock is issued with COGS, payments are stored, and for a known customer the ledger gets `invoice +total` then `payment -amount`. A failure rolls back everything, including the invoice number.
- Only cash can produce change. A balance due needs a customer and `balance + due <= credit_limit`; walk-ins pay in full.
- Held sales (`HLD-`) move no stock and post nothing; completing one renames it to `INV-`.
- Void (`sales.cancel`): completed sale without returns only; restocks at original cost and reverses the ledger.
- Returns (`sales.return`): refund is the price charged (last unit takes the remainder), stock returns at original cost, refund to `account` (customer required) or paid out in cash/bank/card/cheque/other, capped at what was actually paid.

### Pages and API
Pages: POS, Sales list, Sale detail (return and void), print receipt (width from `sales.receipt_width`). API: `GET|POST /api/v1/sales`, `GET /api/v1/sales/{sale}`. Dashboard shows today's sales.

### Known gaps
No PDF invoice (print-friendly receipt only); cash register sessions are tied to the POS since Phase 8 (section 6h); the accounting journal is posted since Phase 7 (section 6g).

## 6g. What Phase 7 contains (Double-entry accounting)

### Database (Phase 7)
`accounts` (per-business chart, found by `system_key` so renumbering is safe), `journal_entries` and `journal_lines` (both immutable; an entry is corrected by a reversing entry, and can be reversed once).

### How it works
- **Automatic posting.** The service that writes a document also writes its journal entry in the same transaction, so a document and its books cannot disagree. All amounts are in the base currency.
  - Sale: Dr cash/bank and receivable, Cr sales and sales tax payable; Dr cost of goods sold, Cr inventory (stocked items only). Void: exact reversal.
  - Sale return: Dr sales returns and tax, Cr cash/bank or receivable; stock goes back Dr inventory, Cr cost of goods sold.
  - Purchase received: Dr inventory (net) and input tax, Cr payable. Purchase return: Dr payable, Cr inventory and input tax; any difference to carrying cost goes to inventory adjustments.
  - Customer receipts and supplier payments, opening balances, party adjustments, stock adjustments, opening stock and stock count differences.
  - Transfers between warehouses post nothing: the business owns the stock either way.
- **Control accounts.** Receivable, payable and inventory cannot be used in a manual journal. They follow customer, supplier and stock records, and the Trial balance page shows a live reconciliation (books vs records) for all three.
- **Closing the books.** A closing date refuses any posting, reversal or void dated on or before it. It can be extended, never moved back.
- **Reports.** Chart with balances, journal list, account ledger with running balance, trial balance, profit and loss, balance sheet (profit to date is shown as its own equity line; there is no year-end close). Trial balance, ledger and P&L can be filtered by branch.
- Permissions: `accounting.view` reads everything, `accounting.manage` creates accounts and manual journals, reverses manual journals and sets the closing date.

### Known gaps
No API endpoints for accounting yet; no year-end closing entry; no bank statement reconciliation; cash registers and expenses arrived in Phase 8 (section 6h); sales and purchases made before Phase 7 was installed have no journal entries (a fresh install has none).

## 6h. What Phase 8 contains (Expenses & cash register)

### Database (Phase 8)
`expense_categories` (each posts to an expense account), `expenses`, `cash_registers`, `cash_sessions` (one cashier's shift on one till) and `cash_movements` (immutable till ledger, signed amounts).

### Expenses
- Someone with `expenses.approve` (Accountant, Owner, Admin) records an approved expense straight away. Anyone else with `expenses.manage` (for example a Branch Manager) records a **pending** one: nothing is paid or posted until an approver approves it. Pending expenses can be edited by their author or an approver, and approved or rejected. Approved expenses are never edited, only voided (the journal entry is reversed, and a cash expense goes back into the voider's till). Rejection and void need a reason.
- Approval posts Dr the category's expense account, Cr cash or bank, dated the expense date, in the expense's branch. Categories are managed by approvers and may only use active expense accounts; a category with expenses cannot be deleted.

### Cash register
- A cashier opens a session on a register with an opening float. One open session per register and per cashier. Only branches the user works in are available.
- Cash sales (net of change), cash refunds, cash voids and cash expenses land on the acting user's open session automatically. Card, bank and cheque payments never touch the drawer. Pay-ins and pay-outs are recorded by hand against the owner (capital, drawings) or the bank (withdrawal, deposit) and post a journal entry. A pay-out cannot exceed what is in the drawer.
- Closing counts the drawer against float plus movements. A shortage or overage is booked to account 6950 Cash Over/Short; an exact count posts nothing. A closed session takes no more cash.
- Setting `sales.require_open_register` (Settings, Invoices & receipts): when on, a sale with a cash payment is refused unless the cashier has an open session, and nothing is saved. When off (the default) sales without a session still work and simply have no shift to attach the cash to.
- Cashiers see only their own sessions. Owners and admins see and can close every session. Registers are created by users with `settings.manage`.

### Known gaps
No expense receipt attachments; no recurring expenses; no cash register API endpoints; sales made while no session was open are not attached to any shift afterwards; one open session per cashier (no shared tills); a closed session cannot be reopened, so fix a wrong count with a pay-in or pay-out.

## 6i. What Phase 9 contains (Reports)

### Reports (Reports menu)
| Report | Shows | Period | Branch |
|---|---|---|---|
| Sales by day | invoices, net sales, discounts, tax, total, cost of goods, gross profit, refunds paid | yes | yes |
| Sales by product | quantity, returned, net revenue, cost, profit, margin % | yes | yes |
| Sales by payment method | cash, card, bank, cheque, other, and the amount sold on credit | yes | yes |
| Sales by cashier | invoices, net sales, discounts given, total | yes | yes |
| Purchases by supplier | received purchases: net cost, tax, total in base currency | yes | yes |
| Stock valuation | quantity, average cost and value per product and warehouse, right now | no | no |
| Low and out of stock | products at or below their minimum, right now | no | no |
| Customer balances / Supplier balances | who owes whom, right now | no | no |
| Expenses by category | approved expenses only | yes | yes |

Profit and loss, balance sheet, trial balance and ledgers stay under Accounting.

### How they behave
- Every report has the same shape (columns, rows, totals, optional chart), so one page and one exporter serve all of them. Bar and line charts are drawn with Chart.js.
- Money and quantities are exact decimal strings; only the chart points are floats.
- Users only see their own branches and warehouses. Asking for a branch you do not work in returns nothing.
- Days follow the **business timezone**: a sale made at 22:30 UTC in Karachi belongs to the next day.
- Only completed sales count: held and voided sales are left out. Refunds are shown on the day they were paid, separately, not netted into net sales.
- "Gross profit" here is net sales minus cost of goods, exactly the figure on the Accounting profit and loss when there are no returns (the tests check this).
- **Export CSV** (needs `reports.export`) streams every row as UTF-8 with a byte-order mark so Excel opens it correctly; the screen shows the first 500 rows. Text cells that start with `=`, `+`, `-` or `@` are prefixed with an apostrophe so a product named like a formula cannot run in a spreadsheet. The page also prints cleanly (Print button).
- Periods are limited to 400 days.

### Known gaps
No customer or supplier ageing (payments are not allocated to individual invoices); no scheduled or emailed reports; no tax return report.

## 6j. What Phase 10 contains (Finalization)
- **Security headers** on web and API responses (`SecurityHeaders` middleware); the authenticated API is throttled to 120 requests per minute.
- **Route audit test**: every route must carry an auth middleware unless on a short public list; guests are redirected or get 401.
- **PDF invoice**: `GET /sales/{sale}/pdf` (button on the sale page), A4, business details, items, totals, payments, invoice terms and footer from settings.
- **XLSX export**: `GET /reports/{type}/export?format=xlsx` (CSV remains the default). Numbers are real numbers; text is forced to text so it cannot become a formula.
- **Report API**: `GET /api/v1/reports` and `/api/v1/reports/{type}`.
- **`php artisan pos:verify-books [--business=ID]`**: checks the trial balance and the three reconciliations for every business; exit code 1 on any difference. Schedule it nightly (see `docs/DEPLOYMENT.md`).
- **Fast POS totals**: the till prices the cart in the browser (`resources/js/Utils/pricing.js`, exact integer maths, same tax rules as `TaxCalculator`, covered by Vitest), so totals update instantly. The server re-checks in the background and when Pay is pressed; its figures are the ones charged and stored.
- Docs: `docs/DEPLOYMENT.md`, `docs/API.md`, `docs/SECURITY.md`.
- Tests: `tests/Feature/Phase10Test.php`.

### Known gaps
Not implemented: notifications, two-factor login, Content-Security-Policy, year-end close, bank reconciliation, partial purchase receipts, landed cost, invoice-level payment allocation and ageing, journal entries for sales made before Phase 7. The PDF and XLSX paths have never been rendered.

## 7. Defaults chosen (ambiguities)
- Business owner creating users can assign any role except Super Admin.
- Branch codes are upper-cased; only one main branch; main branch can't be deleted/deactivated.
- Only the Super Admin has `businesses.manage` (create businesses).
- Receipt width 80 mm default; costing default weighted average.

## 8. Remaining work
- [x] Phase 1 — Foundation (written; run `php artisan test` to validate)
- [x] Phase 2 — Categories, brands, units, products, variants, images, taxes, warehouses, price history, bulk pricing, Excel import/export (written; run `php artisan test` to validate)
- [x] Phase 3 — Inventory: stock, immutable ledger, average/FIFO/FEFO costing, adjustments, transfers, counts, alerts, expiry (written; run `php artisan test` to validate)
- [x] Phase 4 — Customers & suppliers, immutable party ledgers, receipts/payments, adjustments, statements (written; run `php artisan test` to validate)
- [x] Phase 5 — Purchases: orders, receiving into stock, multi-currency, supplier ledger posting, returns (written; run `php artisan test` to validate)
- [x] Phase 6 — POS & sales: server-priced checkout, split payments, credit sales, hold/resume, void, returns, receipt (written; run `php artisan test` to validate)
- [x] Phase 7 — Double-entry accounting: chart of accounts, immutable journals, automatic posting from every document, reconciliation, trial balance, P&L, balance sheet, period closing (written; run `php artisan test` to validate)
- [x] Phase 8 — Cash registers & expenses: shifts with float and counted close, automatic cash attribution, pay-ins/outs, expense approval workflow, cash over/short (written; run `php artisan test` to validate). Bank accounts and reconciliation are not included
- [x] Phase 9 — Reports: sales, products, payments, cashiers, purchases, stock valuation, low stock, balances, expenses; Chart.js charts, CSV export, branch scoping (written; run `php artisan test` to validate)
- [x] Phase 10 — Finalization: security headers and route audit, PDF invoice, XLSX export, report API, books verification command, deployment/security docs (written; run `php artisan test` to validate). Notifications were not built
