<?php

namespace App\Http\Controllers;

use App\Actions\BuildSpendingBreakdown;
use App\Enums\ExpenseCategory;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Jobs\CategorizeExpensesJob;
use App\Models\CashPurchase;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpendingController extends Controller
{
    public function index(Request $request, BuildSpendingBreakdown $buildSpendingBreakdown): Response
    {
        return Inertia::render('spending/index', [
            'breakdown' => $buildSpendingBreakdown->handle($request->user(), $this->selectedMonth($request)),
            'categories' => array_map(
                fn (ExpenseCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'color' => $category->color(),
                ],
                ExpenseCategory::cases(),
            ),
        ]);
    }

    public function updateCashCategory(UpdateExpenseCategoryRequest $request, CashPurchase $cashPurchase): RedirectResponse
    {
        $this->storeCategory($cashPurchase, $request->validated('category'));

        return back();
    }

    /**
     * A categoria de uma compra parcelada mora na compra, nao na parcela: mudar
     * aqui muda o gasto em todos os meses do parcelamento, que e o que o
     * usuario espera de "esse sofa e da categoria Casa".
     */
    public function updatePurchaseCategory(UpdateExpenseCategoryRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->storeCategory($purchase, $request->validated('category'));

        return back();
    }

    public function categorize(Request $request): RedirectResponse
    {
        CategorizeExpensesJob::dispatch($request->user()->id);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Estamos classificando os gastos que ainda não têm categoria. Atualize em instantes.',
        ]);

        return back();
    }

    private function storeCategory(CashPurchase|Purchase $model, ?string $category): void
    {
        $model->update([
            'category' => $category,
            'category_is_manual' => $category !== null,
        ]);
    }

    /**
     * Mes invalido cai no mes corrente em vez de estourar: o parametro vem da
     * barra de endereco e nao vale derrubar a tela por causa dele.
     */
    private function selectedMonth(Request $request): CarbonImmutable
    {
        $month = $request->query('mes');

        if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return CarbonImmutable::now()->startOfMonth();
        }

        return CarbonImmutable::parse("{$month}-01")->startOfMonth();
    }
}
