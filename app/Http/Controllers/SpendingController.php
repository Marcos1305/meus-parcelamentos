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
            'breakdown' => $buildSpendingBreakdown->handle(
                $request->user(),
                $this->selectedMonth($request),
                categoryFilter: $this->categoryFilter($request),
                search: $this->search($request),
            ),
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
     *
     * A faixa e conferida junto com a forma. So o formato deixaria passar
     * "2026-13", que o Carbon recusa lancando excecao — e "2026-00", que ele
     * aceita em silencio como dezembro do ano anterior, o que e pior: a tela
     * abriria mostrando outro mes sem dizer nada.
     */
    private function selectedMonth(Request $request): CarbonImmutable
    {
        $month = $request->query('mes');

        if (! is_string($month) || preg_match('/^(\d{4})-(\d{2})$/', $month, $matches) !== 1) {
            return CarbonImmutable::now()->startOfMonth();
        }

        $number = (int) $matches[2];

        if ($number < 1 || $number > 12) {
            return CarbonImmutable::now()->startOfMonth();
        }

        return CarbonImmutable::create((int) $matches[1], $number, 1);
    }

    /**
     * Le o filtro de categoria da url (?categorias=mercado,sem-categoria) e
     * descarta em silencio qualquer valor que nao seja uma categoria valida
     * ou o balde "sem categoria": mesma regra do mes invalido, o parametro
     * vem da barra de enderecos e uma categoria inexistente nao pode derrubar
     * a tela nem virar erro de validacao.
     *
     * O retorno segue a ordem canonica do enum (com "sem-categoria" por
     * ultimo), nao a ordem em que apareceu na url, para que active_categories
     * saia deterministico independente de como o link foi montado.
     *
     * @return array<int, string>
     */
    private function categoryFilter(Request $request): array
    {
        $raw = $request->query('categorias');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $requested = array_map('trim', explode(',', $raw));

        return collect([...ExpenseCategory::values(), BuildSpendingBreakdown::UNCATEGORIZED_CATEGORY])
            ->filter(fn (string $candidate): bool => in_array($candidate, $requested, true))
            ->values()
            ->all();
    }

    /**
     * Mesma regra do mes e da categoria: o termo vem da barra de enderecos,
     * entao qualquer coisa que nao seja texto vira busca vazia em vez de
     * derrubar a tela. O trim evita que um espaco solto conte como busca
     * ativa e esvazie a lista sem o usuario entender por que.
     */
    private function search(Request $request): string
    {
        $term = $request->query('busca');

        return is_string($term) ? trim($term) : '';
    }
}
