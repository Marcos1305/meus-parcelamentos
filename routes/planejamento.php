<?php

use App\Http\Controllers\BudgetLineController;
use App\Http\Controllers\MonthAmountController;
use App\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('planejamento', [PlanController::class, 'index'])->name('plan.index');
    Route::post('planejamento/linhas', [BudgetLineController::class, 'store'])->name('plan.lines.store');
    Route::patch('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'update'])->name('plan.lines.update');
    Route::delete('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'destroy'])->name('plan.lines.destroy');
    Route::put('planejamento/linhas/{budgetLine}/{month}', [MonthAmountController::class, 'line'])->name('plan.lines.amount');
    Route::put('planejamento/cartoes/{card}/{month}', [MonthAmountController::class, 'card'])->name('plan.cards.amount');
});
