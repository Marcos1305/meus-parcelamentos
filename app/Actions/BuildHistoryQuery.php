<?php

namespace App\Actions;

use App\Enums\InstallmentStatus;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BuildHistoryQuery
{
    /**
     * Colunas que a tela oferece para ordenar. `status` fica de fora de
     * proposito: ele e derivado de `due_date` comparada com hoje, entao
     * "ordenar por status" seria ordenar por vencimento com outro nome, e a
     * tela ofereceria dois controles que fazem a mesma coisa.
     */
    private const array SORTABLE_COLUMNS = ['description', 'total', 'card', 'number', 'due_date'];

    /**
     * @return array{month: string, status: string, card: string, q: string, sort: string, dir: 'asc'|'desc'}
     */
    public function filters(Request $request): array
    {
        $term = $request->query('q', '');
        $sort = $request->query('sort');
        $direction = $request->query('dir');

        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
            'q' => is_string($term) ? trim($term) : '',
            'sort' => is_string($sort) && in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'due_date',
            'dir' => is_string($direction) && in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc',
        ];
    }

    /**
     * @param  array{month: string, status: string, card: string, q: string, sort: string, dir: 'asc'|'desc'}  $filters
     * @return Builder<Installment>
     */
    public function handle(User $user, array $filters): Builder
    {
        $query = Installment::query()
            ->forUser($user)
            ->with(['purchase.card']);

        if ($filters['month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filters['month']) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01');

            $query->whereBetween('due_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        if ($filters['card'] !== 'all') {
            $query->whereHas(
                'purchase',
                fn (Builder $purchases) => $purchases->where('card_id', (int) $filters['card']),
            );
        }

        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';

            $query->whereHas('purchase', fn (Builder $purchases) => $purchases
                ->where('description', 'like', $term)
                ->orWhere('merchant', 'like', $term)
                ->orWhere('notes', 'like', $term));
        }

        $today = CarbonImmutable::now();

        match (InstallmentStatus::tryFrom($filters['status'])) {
            InstallmentStatus::Paid => $query->whereDate('due_date', '<', $today->toDateString()),
            InstallmentStatus::Upcoming => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            InstallmentStatus::Pending => $query->whereDate('due_date', '>', $today->endOfMonth()->toDateString()),
            null => null,
        };

        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * As colunas de `purchases` e `cards` sao ordenadas por subconsulta
     * correlacionada, e nao por join: o `whereHas` do filtro de cartao e o
     * `with` do carregamento contam com uma query sem joins que dupliquem
     * linhas.
     *
     * As duas colunas de texto (`description`, nome do cartao) ordenam pelo
     * valor em minusculas: o SQLite compara texto por bytes, entao sem isso
     * uma lista com "Zebra, banana, iPhone 15" sai com "banana" e
     * "iPhone 15" depois de "Zebra" -- o byte da minuscula ASCII e maior que
     * o da maiuscula. `lower()` e aplicado dentro da propria subconsulta
     * (`selectRaw`) e nao como um `collate nocase` colado na clausula
     * externa: o texto passado a `selectRaw` e sempre o mesmo literal,
     * entao nao depende de concatenar SQL dinamico para funcionar. Acento
     * continua fora de escopo -- `lower()` so resolve caixa em ASCII, nao
     * acentuacao.
     *
     * O desempate por `id` fecha toda ordenacao. Sem ele, duas parcelas com o
     * mesmo vencimento podem trocar de posicao entre requisicoes e sumir da
     * paginacao — a linha aparece na pagina 1 numa carga e na pagina 2 na
     * seguinte, sem nunca ser vista.
     *
     * @param  Builder<Installment>  $query
     * @param  array{month: string, status: string, card: string, q: string, sort: string, dir: 'asc'|'desc'}  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $direction = $filters['dir'];

        match ($filters['sort']) {
            'description' => $query->orderBy(
                Purchase::selectRaw('lower(description)')->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'total' => $query->orderBy(
                Purchase::select('total_amount_cents')->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'card' => $query->orderBy(
                Card::selectRaw('lower(cards.name)')
                    ->join('purchases', 'purchases.card_id', '=', 'cards.id')
                    ->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'number' => $query->orderBy('number', $direction),
            default => $query->orderBy('due_date', $direction),
        };

        $query->orderBy('id');
    }
}
