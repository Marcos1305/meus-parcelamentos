<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('cartoes', [CardController::class, 'index'])->name('cards.index');
    Route::get('cartoes/novo', [CardController::class, 'create'])->name('cards.create');
    Route::post('cartoes', [CardController::class, 'store'])->name('cards.store');
    Route::get('cartoes/{card}/editar', [CardController::class, 'edit'])->name('cards.edit')->can('update', 'card');
    Route::patch('cartoes/{card}', [CardController::class, 'update'])->name('cards.update')->can('update', 'card');
    Route::delete('cartoes/{card}', [CardController::class, 'destroy'])->name('cards.destroy')->can('delete', 'card');

    Route::get('lancamentos/novo', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('lancamentos', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::delete('lancamentos/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy')->can('delete', 'purchase');

    Route::get('historico', [HistoryController::class, 'index'])->name('history.index');
});
