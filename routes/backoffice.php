<?php

use App\Http\Controllers\Backoffice\BackofficeUserController;
use App\Http\Controllers\Backoffice\BusinessController;
use App\Http\Controllers\Backoffice\BusinessPreparationController;
use App\Http\Controllers\Backoffice\CurrentBusinessController;
use App\Http\Controllers\Backoffice\CurrentBusinessEmployeeController;
use App\Http\Controllers\Backoffice\CurrentBusinessMemberController;
use App\Http\Controllers\Backoffice\ExpenseController;
use App\Http\Controllers\Backoffice\ProductController;
use App\Http\Controllers\Backoffice\PurchaseController;
use App\Http\Controllers\Backoffice\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'backoffice.access'])->prefix('backoffice')->name('backoffice.')->group(function () {
    Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
    Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');

    Route::put('current-business', [CurrentBusinessController::class, 'update'])->name('current-business.update');

    Route::middleware('backoffice.super-admin')->group(function () {
        Route::get('access', [BackofficeUserController::class, 'index'])->name('access.index');
        Route::post('access', [BackofficeUserController::class, 'store'])->name('access.store');
        Route::patch('access/{user}', [BackofficeUserController::class, 'update'])->name('access.update');
    });

    Route::middleware('current.business')->group(function () {
        Route::get('preparation', [BusinessPreparationController::class, 'index'])->name('preparation.index');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::post('sales/{entityId}/return', [SaleController::class, 'markReturned'])->name('sales.return');
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::post('purchases/{entityId}/complete', [PurchaseController::class, 'complete'])->name('purchases.complete');
        Route::post('purchases/{entityId}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
        Route::delete('purchases/{entityId}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('expenses/{entityId}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{entityId}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::get('team', [CurrentBusinessMemberController::class, 'index'])->name('team.index');
        Route::post('team', [CurrentBusinessMemberController::class, 'store'])->name('team.store');
        Route::post('team/employees', [CurrentBusinessEmployeeController::class, 'store'])->name('team.employees.store');
        Route::put('team/employees/{entityId}', [CurrentBusinessEmployeeController::class, 'update'])->name('team.employees.update');
        Route::delete('team/employees/{entityId}', [CurrentBusinessEmployeeController::class, 'destroy'])->name('team.employees.destroy');
    });
});
