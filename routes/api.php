<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use Illuminate\Support\Facades\Route;

// Base path is /api (Laravel) so these are served at /api/v1/...
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('auth.login');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:120,1'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::apiResource('businesses', BusinessController::class)->except('destroy');
        Route::apiResource('branches', BranchController::class)->except('destroy');
        Route::apiResource('currencies', CurrencyController::class)->except(['show', 'destroy'])->parameters(['currencies' => 'currency']);
        Route::apiResource('products', ProductController::class);
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        // Permissions are checked in the controller / form requests (Sanctum tokens do not use the web guard).
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])->name('inventory.adjustments.store');
        Route::post('inventory/transfers', [InventoryController::class, 'transfer'])->name('inventory.transfers.store');
        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
        Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');
        foreach (['customers' => CustomerController::class, 'suppliers' => SupplierController::class] as $path => $controller) {
            Route::get($path, [$controller, 'index'])->name($path.'.index');
            Route::post($path, [$controller, 'store'])->name($path.'.store');
            Route::get($path.'/{party}', [$controller, 'show'])->name($path.'.show');
            Route::put($path.'/{party}', [$controller, 'update'])->name($path.'.update');
            Route::post($path.'/{party}/payments', [$controller, 'payment'])->name($path.'.payments.store');
        }
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{type}', [ReportController::class, 'show'])->name('reports.show');
        Route::post('currencies/{currency}/rates', [CurrencyController::class, 'storeRate'])->name('currencies.rates.store');
    });
});
