<?php

use App\Http\Controllers\BudgetLineController;
use App\Http\Controllers\HiddenMonthController;
use App\Http\Controllers\MonthAmountController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RowColorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('planejamento', [PlanController::class, 'index'])->name('plan.index');
    Route::put('planejamento/meses-ocultos/{year}', [HiddenMonthController::class, 'update'])
        ->whereNumber('year')
        ->name('plan.months.hidden');
    Route::post('planejamento/linhas', [BudgetLineController::class, 'store'])->name('plan.lines.store');
    Route::patch('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'update'])->name('plan.lines.update');
    Route::delete('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'destroy'])->name('plan.lines.destroy');
    /**
     * As rotas de cor vêm antes das de valor mensal: o segmento fixo `cor`
     * casaria com o curinga `{month}` se a ordem fosse invertida.
     */
    Route::put('planejamento/linhas/{budgetLine}/cor', [RowColorController::class, 'line'])->name('plan.lines.color');
    Route::put('planejamento/cartoes/{card}/cor', [RowColorController::class, 'card'])->name('plan.cards.color');
    Route::put('planejamento/linhas/{budgetLine}/{month}', [MonthAmountController::class, 'line'])->name('plan.lines.amount');
    Route::put('planejamento/cartoes/{card}/{month}', [MonthAmountController::class, 'card'])->name('plan.cards.amount');
});
