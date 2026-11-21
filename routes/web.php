<?php

use App\Http\Controllers\Web;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [Web\AuthController::class, 'create'])->name('login');
    Route::post('/login', [Web\AuthController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/forgot-password', [Web\AuthController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [Web\AuthController::class, 'sendResetLink'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [Web\AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [Web\AuthController::class, 'resetPassword'])->name('password.store');
});

Route::middleware(['auth', AuthenticateSession::class, 'tenant'])->group(function () {
    Route::post('/logout', [Web\AuthController::class, 'destroy'])->name('logout');
    Route::post('/context', Web\ContextController::class)->name('context.switch');

    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', Web\DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('/profile', [Web\ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [Web\ProfileController::class, 'update'])->name('profile.update'); // POST: supports avatar upload
    Route::put('/profile/password', [Web\ProfileController::class, 'password'])->name('profile.password');
    Route::post('/profile/logout-others', [Web\ProfileController::class, 'logoutOthers'])->name('profile.logout-others');

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings/business', [Web\BusinessSettingsController::class, 'edit'])->name('settings.business');
        Route::post('/settings/business', [Web\BusinessSettingsController::class, 'update'])->name('settings.business.update');
    });

    Route::middleware('permission:branches.manage')->group(function () {
        Route::get('/settings/branches', [Web\BranchController::class, 'index'])->name('branches.index');
        Route::post('/settings/branches', [Web\BranchController::class, 'store'])->name('branches.store');
        Route::put('/settings/branches/{branch}', [Web\BranchController::class, 'update'])->name('branches.update');
        Route::delete('/settings/branches/{branch}', [Web\BranchController::class, 'destroy'])->name('branches.destroy');
    });

    Route::middleware('permission:currencies.manage')->group(function () {
        Route::get('/settings/currencies', [Web\CurrencyController::class, 'index'])->name('currencies.index');
        Route::post('/settings/currencies', [Web\CurrencyController::class, 'store'])->name('currencies.store');
        Route::put('/settings/currencies/{currency}', [Web\CurrencyController::class, 'update'])->name('currencies.update');
        Route::post('/settings/currencies/{currency}/rates', [Web\CurrencyController::class, 'storeRate'])->name('currencies.rates.store');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [Web\UserController::class, 'index'])->name('users.index');
        Route::post('/users', [Web\UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [Web\UserController::class, 'update'])->name('users.update');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('/roles', [Web\RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [Web\RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [Web\RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [Web\RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware('permission:businesses.manage')->group(function () {
        Route::get('/businesses', [Web\BusinessController::class, 'index'])->name('businesses.index');
        Route::post('/businesses', [Web\BusinessController::class, 'store'])->name('businesses.store');
    });

    // ---- Phase 2: catalog & master data --------------------------------------------------------
    // Static /products/* paths are declared before /products/{product} so they are not captured as an id.
    Route::middleware('permission:products.view')->group(function () {
        Route::get('/products', [Web\ProductController::class, 'index'])->name('products.index');
        Route::get('/products/lookup', [Web\ProductController::class, 'lookup'])->name('products.lookup');
        Route::get('/products/export', [Web\ProductController::class, 'export'])->name('products.export');
    });
    Route::middleware('permission:products.create')->group(function () {
        Route::get('/products/create', [Web\ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [Web\ProductController::class, 'store'])->name('products.store');
        Route::get('/products/import-template', [Web\ProductController::class, 'template'])->name('products.template');
    });
    Route::post('/products/import', [Web\ProductController::class, 'import'])->name('products.import'); // needs create + update (checked in the request)
    Route::middleware('permission:products.update')->group(function () {
        Route::post('/products/bulk-prices', [Web\ProductController::class, 'bulkPrices'])->name('products.bulk-prices');
        Route::get('/products/{product}/edit', [Web\ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [Web\ProductController::class, 'update'])->name('products.update');
    });
    Route::get('/products/{product}', [Web\ProductController::class, 'show'])->middleware('permission:products.view')->name('products.show');
    Route::delete('/products/{product}', [Web\ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');

    Route::middleware('permission:categories.manage')->group(function () {
        Route::get('/categories', [Web\CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [Web\CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [Web\CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [Web\CategoryController::class, 'destroy'])->name('categories.destroy');
    });
    Route::middleware('permission:brands.manage')->group(function () {
        Route::get('/brands', [Web\BrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [Web\BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [Web\BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [Web\BrandController::class, 'destroy'])->name('brands.destroy');
    });
    Route::middleware('permission:units.manage')->group(function () {
        Route::get('/units', [Web\UnitController::class, 'index'])->name('units.index');
        Route::post('/units', [Web\UnitController::class, 'store'])->name('units.store');
        Route::put('/units/{unit}', [Web\UnitController::class, 'update'])->name('units.update');
        Route::delete('/units/{unit}', [Web\UnitController::class, 'destroy'])->name('units.destroy');
    });
    Route::middleware('permission:warehouses.manage')->group(function () {
        Route::get('/warehouses', [Web\WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [Web\WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('/warehouses/{warehouse}', [Web\WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [Web\WarehouseController::class, 'destroy'])->name('warehouses.destroy');
    });
    Route::middleware('permission:taxes.manage')->group(function () {
        Route::get('/settings/taxes', [Web\TaxController::class, 'index'])->name('taxes.index');
        Route::post('/settings/taxes', [Web\TaxController::class, 'store'])->name('taxes.store');
        Route::put('/settings/taxes/{tax}', [Web\TaxController::class, 'update'])->name('taxes.update');
        Route::delete('/settings/taxes/{tax}', [Web\TaxController::class, 'destroy'])->name('taxes.destroy');
    });

    // ---- Phase 3: inventory ----------------------------------------------------------------------
    Route::middleware('permission:inventory.view')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [Web\InventoryController::class, 'index'])->name('index');
        Route::get('/movements', [Web\InventoryController::class, 'movements'])->name('movements');
        Route::get('/expiry', [Web\InventoryController::class, 'expiry'])->name('expiry');
        Route::get('/adjustments', [Web\StockAdjustmentController::class, 'index'])->name('adjustments.index');
        Route::get('/transfers', [Web\StockTransferController::class, 'index'])->name('transfers.index');
        Route::get('/counts', [Web\StockCountController::class, 'index'])->name('counts.index');
    });
    Route::middleware('permission:inventory.adjust')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/adjustments/create', [Web\StockAdjustmentController::class, 'create'])->name('adjustments.create');
        Route::post('/adjustments', [Web\StockAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::post('/counts', [Web\StockCountController::class, 'store'])->name('counts.store');
        Route::put('/counts/{count}', [Web\StockCountController::class, 'update'])->name('counts.update');
        Route::post('/counts/{count}/complete', [Web\StockCountController::class, 'complete'])->name('counts.complete');
        Route::post('/counts/{count}/cancel', [Web\StockCountController::class, 'cancel'])->name('counts.cancel');
    });
    Route::middleware('permission:inventory.transfer')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/transfers/create', [Web\StockTransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [Web\StockTransferController::class, 'store'])->name('transfers.store');
    });
    Route::middleware('permission:inventory.view')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/adjustments/{adjustment}', [Web\StockAdjustmentController::class, 'show'])->name('adjustments.show');
        Route::get('/transfers/{transfer}', [Web\StockTransferController::class, 'show'])->name('transfers.show');
        Route::get('/counts/{count}', [Web\StockCountController::class, 'show'])->name('counts.show');
    });

    // ---- Phase 4: customers & suppliers ----------------------------------------------------------
    foreach (['customers' => Web\CustomerController::class, 'suppliers' => Web\SupplierController::class] as $path => $controller) {
        Route::middleware('permission:'.$path.'.manage')->prefix($path)->name($path.'.')->group(function () use ($controller) {
            Route::get('/', [$controller, 'index'])->name('index');
            Route::post('/', [$controller, 'store'])->name('store');
            Route::get('/lookup', [$controller, 'lookup'])->name('lookup');
            Route::get('/{party}', [$controller, 'show'])->whereNumber('party')->name('show');
            Route::put('/{party}', [$controller, 'update'])->whereNumber('party')->name('update');
            Route::delete('/{party}', [$controller, 'destroy'])->whereNumber('party')->name('destroy');
            Route::post('/{party}/payments', [$controller, 'payment'])->whereNumber('party')->name('payments.store');
            Route::post('/{party}/adjustments', [$controller, 'adjust'])->whereNumber('party')->name('adjustments.store');
        });
    }

    // ---- Phase 5: purchases ----------------------------------------------------------------------
    Route::middleware('permission:purchases.view')->prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/', [Web\PurchaseController::class, 'index'])->name('index');
    });
    Route::middleware('permission:purchases.create')->prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/create', [Web\PurchaseController::class, 'create'])->name('create');
        Route::post('/', [Web\PurchaseController::class, 'store'])->name('store');
    });
    Route::middleware('permission:purchases.update')->prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/{purchase}/edit', [Web\PurchaseController::class, 'edit'])->name('edit');
        Route::put('/{purchase}', [Web\PurchaseController::class, 'update'])->name('update');
        Route::post('/{purchase}/cancel', [Web\PurchaseController::class, 'cancel'])->name('cancel');
    });
    Route::post('/purchases/{purchase}/receive', [Web\PurchaseController::class, 'receive'])->middleware('permission:purchases.receive')->name('purchases.receive');
    Route::post('/purchases/{purchase}/returns', [Web\PurchaseController::class, 'storeReturn'])->middleware('permission:purchases.return')->name('purchases.returns.store');
    Route::get('/purchases/{purchase}', [Web\PurchaseController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');

    // ---- Phase 6: POS & sales --------------------------------------------------------------------
    Route::middleware('permission:sales.create')->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [Web\PosController::class, 'index'])->name('index');
        Route::post('/quote', [Web\PosController::class, 'quote'])->name('quote');
        Route::post('/hold', [Web\PosController::class, 'hold'])->name('hold');
        Route::post('/complete', [Web\PosController::class, 'complete'])->name('complete');
        Route::get('/held', [Web\PosController::class, 'held'])->name('held');
        Route::delete('/held/{sale}', [Web\PosController::class, 'discard'])->name('discard');
    });
    Route::middleware('permission:sales.view')->prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [Web\SaleController::class, 'index'])->name('index');
        Route::get('/{sale}', [Web\SaleController::class, 'show'])->whereNumber('sale')->name('show');
        Route::get('/{sale}/receipt', [Web\SaleController::class, 'receipt'])->whereNumber('sale')->name('receipt');
        Route::get('/{sale}/pdf', [Web\SaleController::class, 'pdf'])->whereNumber('sale')->name('pdf');
    });
    Route::post('/sales/{sale}/void', [Web\SaleController::class, 'void'])->whereNumber('sale')->middleware('permission:sales.cancel')->name('sales.void');
    Route::post('/sales/{sale}/returns', [Web\SaleController::class, 'storeReturn'])->whereNumber('sale')->middleware('permission:sales.return')->name('sales.returns.store');

    // ---- Phase 7: accounting ---------------------------------------------------------------------
    Route::middleware('permission:accounting.view')->prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/', [Web\AccountController::class, 'index'])->name('index');
        Route::get('/trial-balance', [Web\AccountingReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/income-statement', [Web\AccountingReportController::class, 'incomeStatement'])->name('income-statement');
        Route::get('/balance-sheet', [Web\AccountingReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/journal', [Web\JournalController::class, 'index'])->name('journal.index');
        Route::get('/journal/{entry}', [Web\JournalController::class, 'show'])->whereNumber('entry')->name('journal.show');
        Route::get('/accounts/{account}/ledger', [Web\AccountingReportController::class, 'ledger'])->whereNumber('account')->name('ledger');
    });
    Route::middleware('permission:accounting.manage')->prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/journal/create', [Web\JournalController::class, 'create'])->name('journal.create');
        Route::post('/journal', [Web\JournalController::class, 'store'])->name('journal.store');
        Route::post('/journal/{entry}/reverse', [Web\JournalController::class, 'reverse'])->whereNumber('entry')->name('journal.reverse');
        Route::post('/accounts', [Web\AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [Web\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [Web\AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/lock', [Web\AccountController::class, 'lock'])->name('lock');
    });

    // ---- Phase 8: expenses and cash registers ----------------------------------------------------
    Route::middleware('permission:expenses.manage')->prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [Web\ExpenseController::class, 'index'])->name('index');
        Route::post('/', [Web\ExpenseController::class, 'store'])->name('store');
        Route::put('/{expense}', [Web\ExpenseController::class, 'update'])->whereNumber('expense')->name('update');
    });
    Route::middleware('permission:expenses.approve')->prefix('expenses')->name('expenses.')->group(function () {
        Route::post('/{expense}/approve', [Web\ExpenseController::class, 'approve'])->whereNumber('expense')->name('approve');
        Route::post('/{expense}/reject', [Web\ExpenseController::class, 'reject'])->whereNumber('expense')->name('reject');
        Route::post('/{expense}/void', [Web\ExpenseController::class, 'void'])->whereNumber('expense')->name('void');
        Route::get('/categories', [Web\ExpenseController::class, 'categories'])->name('categories');
        Route::post('/categories', [Web\ExpenseController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [Web\ExpenseController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [Web\ExpenseController::class, 'destroyCategory'])->name('categories.destroy');
    });
    Route::middleware('permission:cash_register.manage')->prefix('cash-register')->name('cash-register.')->group(function () {
        Route::get('/', [Web\CashRegisterController::class, 'index'])->name('index');
        Route::post('/registers/{register}/open', [Web\CashRegisterController::class, 'open'])->whereNumber('register')->name('open');
        Route::get('/sessions/{session}', [Web\CashRegisterController::class, 'show'])->whereNumber('session')->name('show');
        Route::post('/sessions/{session}/movements', [Web\CashRegisterController::class, 'movement'])->whereNumber('session')->name('movement');
        Route::post('/sessions/{session}/close', [Web\CashRegisterController::class, 'close'])->whereNumber('session')->name('close');
    });
    Route::middleware('permission:settings.manage')->prefix('cash-register')->name('cash-register.')->group(function () {
        Route::post('/registers', [Web\CashRegisterController::class, 'storeRegister'])->name('registers.store');
        Route::put('/registers/{register}', [Web\CashRegisterController::class, 'updateRegister'])->whereNumber('register')->name('registers.update');
    });

    // ---- Phase 9: reports ------------------------------------------------------------------------
    Route::middleware('permission:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [Web\ReportController::class, 'index'])->name('index');
        Route::get('/{type}', [Web\ReportController::class, 'show'])->name('show');
        Route::get('/{type}/export', [Web\ReportController::class, 'export'])->middleware('permission:reports.export')->name('export');
    });

    Route::get('/activity-logs', Web\ActivityLogController::class)->middleware('permission:audit.view')->name('activity-logs');
});
