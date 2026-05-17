<?php

use App\Http\Controllers\Backoffice\BackofficeUserController;
use App\Http\Controllers\Backoffice\BusinessController;
use App\Http\Controllers\Backoffice\BusinessPreparationController;
use App\Http\Controllers\Backoffice\CurrentBusinessController;
use App\Http\Controllers\Backoffice\CurrentBusinessEmployeeController;
use App\Http\Controllers\Backoffice\CurrentBusinessMemberController;
use App\Http\Controllers\Backoffice\ExpenseController;
use App\Http\Controllers\Backoffice\InventoryController;
use App\Http\Controllers\Backoffice\LicensePricingController;
use App\Http\Controllers\Backoffice\PointOfSaleController;
use App\Http\Controllers\Backoffice\ProductController;
use App\Http\Controllers\Backoffice\ProductLossController;
use App\Http\Controllers\Backoffice\PurchaseController;
use App\Http\Controllers\Backoffice\SaleController;
use App\Http\Controllers\Backoffice\StockAdjustmentController;
use App\Http\Controllers\Backoffice\StockMovementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'backoffice.access'])->prefix('backoffice')->name('backoffice.')->group(function () {
    Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
    Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::put('businesses/{business}', [BusinessController::class, 'update'])->name('businesses.update');
    Route::delete('businesses/{business}', [BusinessController::class, 'destroy'])->name('businesses.destroy');
    Route::post('businesses/{business}/restore', [BusinessController::class, 'restore'])->name('businesses.restore');
    Route::post('businesses/{business}/sync-token', [BusinessController::class, 'issueSyncToken'])->name('businesses.sync-token');

    Route::put('current-business', [CurrentBusinessController::class, 'update'])->name('current-business.update');

    Route::middleware('backoffice.super-admin')->group(function () {
        Route::get('access', [BackofficeUserController::class, 'index'])->name('access.index');
        Route::post('access', [BackofficeUserController::class, 'store'])->name('access.store');
        Route::patch('access/{user}', [BackofficeUserController::class, 'update'])->name('access.update');
        Route::put('license-pricing/config', [LicensePricingController::class, 'updateConfig'])->name('license-pricing.config.update');
        Route::post('license-pricing/rules', [LicensePricingController::class, 'store'])->name('license-pricing.rules.store');
        Route::put('license-pricing/rules/{licensePricingRule}', [LicensePricingController::class, 'update'])->name('license-pricing.rules.update');
        Route::delete('license-pricing/rules/{licensePricingRule}', [LicensePricingController::class, 'destroy'])->name('license-pricing.rules.destroy');
    });

    Route::middleware('current.business')->group(function () {
        Route::get('preparation', [BusinessPreparationController::class, 'index'])->name('preparation.index');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('sales/export', [SaleController::class, 'export'])->name('sales.export');
        Route::post('sales/{entityId}/return', [SaleController::class, 'markReturned'])->name('sales.return');
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::post('purchases/{entityId}/complete', [PurchaseController::class, 'complete'])->name('purchases.complete');
        Route::post('purchases/{entityId}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
        Route::delete('purchases/{entityId}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
        Route::get('stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
        Route::get('stock-movements/export', [StockMovementController::class, 'export'])->name('stock-movements.export');
        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::get('stock-adjustments/export', [StockAdjustmentController::class, 'export'])->name('stock-adjustments.export');
        Route::get('losses', [ProductLossController::class, 'index'])->name('losses.index');
        Route::get('losses/export', [ProductLossController::class, 'export'])->name('losses.export');
        Route::get('points-of-sale', [PointOfSaleController::class, 'index'])->name('points-of-sale.index');
        Route::get('points-of-sale/{posExternalId}/sessions', [PointOfSaleController::class, 'sessions'])
            ->where('posExternalId', '[A-Za-z0-9_\-]+')
            ->name('points-of-sale.sessions');
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('expenses/{entityId}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{entityId}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::get('team', [CurrentBusinessMemberController::class, 'index'])->name('team.index');
        Route::post('team', [CurrentBusinessMemberController::class, 'store'])->name('team.store');
        Route::put('team/members/{member}', [CurrentBusinessMemberController::class, 'update'])->name('team.members.update');
        Route::delete('team/members/{member}', [CurrentBusinessMemberController::class, 'destroy'])->name('team.members.destroy');
        Route::put('team/members/{member}/password', [CurrentBusinessMemberController::class, 'updatePassword'])->name('team.members.password.update');
        Route::post('team/employees', [CurrentBusinessEmployeeController::class, 'store'])->name('team.employees.store');
        Route::put('team/employees/{entityId}', [CurrentBusinessEmployeeController::class, 'update'])->name('team.employees.update');
        Route::delete('team/employees/{entityId}', [CurrentBusinessEmployeeController::class, 'destroy'])->name('team.employees.destroy');
    });
});
