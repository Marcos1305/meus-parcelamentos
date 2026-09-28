<?php

use App\Http\Controllers\ExportSpendingController;
use App\Http\Controllers\SpendingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('gastos', [SpendingController::class, 'index'])->name('spending.index');
    Route::get('gastos/exportar', ExportSpendingController::class)->name('spending.export');

    Route::patch('gastos/a-vista/{cashPurchase}/categoria', [SpendingController::class, 'updateCashCategory'])
        ->name('spending.cash-category')
        ->can('update', 'cashPurchase');

    Route::patch('gastos/compras/{purchase}/categoria', [SpendingController::class, 'updatePurchaseCategory'])
        ->name('spending.purchase-category')
        ->can('update', 'purchase');

    Route::post('gastos/categorizar', [SpendingController::class, 'categorize'])->name('spending.categorize');
});
