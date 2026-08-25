<?php

namespace App\Actions;

use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSpendingBreakdown
{
    /**
     * Chave sintetica usada para o balde "sem categoria" dentro dos mesmos
     * mapas que carregam as categorias reais (categories() e series()). Nao e
     * um valor de ExpenseCategory: precisa ser distinta de qualquer case do
     * enum para nao colidir, e nunca e aceita pela validacao de PATCH de
     * categoria (UpdateExpenseCategoryRequest), entao nao ha risco de o
     * usuario "escolher" este balde pelo seletor manual.
     *
     * Publica porque o controller precisa dela para validar o filtro de
     * categoria vindo da URL (?categorias=) contra a mesma lista de chaves
     * aceitas aqui, em vez de duplicar o literal 'sem-categoria' em outro
     * arquivo.
     */
    public const UNCATEGORIZED_CATEGORY = 'sem-categoria';

    /**
     * Colunas ordenaveis da lista, na nomenclatura em portugues que a tela de
     * gastos ja usa na url (mes, categorias, busca).
     */
    public const array SORTABLE_COLUMNS = ['descricao', 'cartao', 'data', 'valor', 'categoria'];

    private const UNCATEGORIZED_LABEL = 'Sem categoria';

    /**
     * Cinza neutro, sem variante clara/escura: mesmo hex do papel "muted /
     * axis" da skill dataviz (fora da paleta categorica das treze cores reais
     * de ExpenseCategory, escolhida para maximizar distancia perceptual entre
     * categorias). Croma baixo o suficiente para nao competir como se fosse
     * uma 14a categoria — o objetivo e o oposto: ler como "nao classificado",
     * nao como identidade.
     */
    private const UNCATEGORIZED_COLOR = '#898781';

    /**
     * Regime de caixa: o mes de um gasto e o mes em que a fatura o cobrou. Para
     * a compra a vista isso e o billed_at, gravado na importacao; para a
     * parcelada e o vencimento da parcela. Usar a data da compra para uma e o
     * vencimento para a outra erraria os dois meses na virada do ano.
     *
     * O balde "sem categoria" entra em categories() e months() como qualquer
     * outro membro da soma, nao como excecao: caso contrario o total exibido
     * fica menor que o total real por exatamente o que ainda nao foi
     * classificado, e isso e indistinguivel de uma queda de gasto de verdade
     * no grafico dos ultimos 12 meses.
     *
     * O filtro de categoria (categoryFilter) restringe os quatro blocos de
     * saida (categories, months, entries e o par uncategorized_*) ao mesmo
     * subconjunto: em vez de recalcular cada bloco com uma condicao propria,
     * as colecoes $cash e $installments sao filtradas uma unica vez antes de
     * alimentar os mesmos metodos usados no caminho sem filtro. E assim que a
     * garantia de reconciliacao (barras + balde fecham o total exibido) se
     * mantem verdadeira tambem sob filtro — o "total exibido" passa a ser o
     * total do subconjunto filtrado, nao o total do mes.
     *
     * available_categories nao usa as colecoes filtradas: ele lista as
     * categorias com gasto no mes a partir das colecoes originais, para que os
     * chips de filtro da tela ofereçam todas as opcoes com gasto no mes, nao
     * so as que sobrevivem ao filtro atual (senao o usuario nunca conseguiria
     * ampliar a selecao). A excecao e a categoria hoje filtrada que zerou no
     * mes: ela e adicionada de volta com total zero para o usuario conseguir
     * desmarca-la.
     *
     * A busca livre e a excecao deliberada a tudo isso: ela entra so em
     * entries(). O filtro de categoria e uma particao do gasto — as fatias
     * somam o subconjunto e a reconciliacao segue verdadeira. A busca e uma
     * lente sobre a lista: um grafico que muda a cada tecla digitada nao
     * comunica nada, e o "total do mes" deixaria de ser o total do mes.
     *
     * @param  array<int, string>  $categoryFilter  chaves ja validadas pelo controller (ExpenseCategory::values() + self::UNCATEGORIZED_CATEGORY); vazio significa "todas"
     * @param  string  $search  termo livre casado contra a descricao; vazio significa "sem busca"
     * @param  string  $sort  coluna de ordenacao ja validada pelo controller (self::SORTABLE_COLUMNS)
     * @param  string  $direction  'asc' ou 'desc', ja validada pelo controller
     * @return array{
     *   month: string,
     *   categories: array<int, array{category: string, label: string, color: string, total_cents: int}>,
     *   months: array<int, array{month: string, totals: array<string, int>}>,
     *   entries: array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>,
     *   uncategorized_count: int,
     *   uncategorized_total_cents: int,
     *   uncategorized_color: string,
     *   active_categories: array<int, string>,
     *   available_categories: array<int, array{category: string, label: string, color: string, total_cents: int}>,
     *   search: string,
     *   sort: string,
     *   direction: string,
     * }
     */
    public function handle(User $user, CarbonImmutable $month, int $months = 12, array $categoryFilter = [], string $search = '', string $sort = 'valor', string $direction = 'desc'): array
    {
        $start = $month->startOfMonth();
        $seriesStart = $start->subMonths($months - 1);

        $cashAll = CashPurchase::query()
            ->forUser($user)
            ->with('card:id,name')
            ->whereDate('billed_at', '>=', $seriesStart->toDateString())
            ->whereDate('billed_at', '<=', $start->endOfMonth()->toDateString())
            ->get();

        $installmentsAll = Installment::query()
            ->forUser($user)
            ->with('purchase:id,card_id,description,installments_count,category,category_is_manual', 'purchase.card:id,name')
            ->whereDate('due_date', '>=', $seriesStart->toDateString())
            ->whereDate('due_date', '<=', $start->endOfMonth()->toDateString())
            ->get();

        [$cash, $installments] = $categoryFilter === []
            ? [$cashAll, $installmentsAll]
            : $this->applyCategoryFilter($cashAll, $installmentsAll, $categoryFilter);

        return [
            'month' => $start->format('Y-m'),
            'categories' => $this->categories($cash, $installments, $start),
            'months' => $this->series($cash, $installments, $seriesStart, $months),
            'entries' => $this->entries($cash, $installments, $start, $search, $sort, $direction),
            'uncategorized_count' => $this->uncategorizedCount($cash, $installments, $start),
            'uncategorized_total_cents' => $this->uncategorizedTotalCents($cash, $installments, $start),
            'uncategorized_color' => self::UNCATEGORIZED_COLOR,
            'active_categories' => $categoryFilter,
            'available_categories' => $this->availableCategories($cashAll, $installmentsAll, $start, $categoryFilter),
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @param  array<int, string>  $categoryFilter
     * @return array{0: Collection<int, CashPurchase>, 1: Collection<int, Installment>}
     */
    private function applyCategoryFilter(Collection $cash, Collection $installments, array $categoryFilter): array
    {
        $filteredCash = $cash
            ->filter(fn (CashPurchase $purchase): bool => in_array($purchase->category->value ?? self::UNCATEGORIZED_CATEGORY, $categoryFilter, true))
            ->values();

        $filteredInstallments = $installments
            ->filter(fn (Installment $installment): bool => in_array($installment->purchase->category->value ?? self::UNCATEGORIZED_CATEGORY, $categoryFilter, true))
            ->values();

        return [$filteredCash, $filteredInstallments];
    }

    /**
     * Opcoes dos chips de filtro: as categorias com gasto no mes (calculadas
     * sem filtro), acrescidas de qualquer categoria do filtro atual que nao
     * tenha sobrado nessa lista porque zerou no mes. Sem essa excecao, trocar
     * de mes com um filtro ativo poderia fazer o chip selecionado sumir da
     * tela e o usuario ficaria sem forma de desmarca-lo.
     *
     * @param  Collection<int, CashPurchase>  $cashAll
     * @param  Collection<int, Installment>  $installmentsAll
     * @param  array<int, string>  $categoryFilter
     * @return array<int, array{category: string, label: string, color: string, total_cents: int}>
     */
    private function availableCategories(Collection $cashAll, Collection $installmentsAll, CarbonImmutable $month, array $categoryFilter): array
    {
        $options = $this->categories($cashAll, $installmentsAll, $month);

        $present = array_column($options, 'category');

        foreach ($categoryFilter as $value) {
            if (in_array($value, $present, true)) {
                continue;
            }

            $options[] = [
                'category' => $value,
                'label' => $value === self::UNCATEGORIZED_CATEGORY ? self::UNCATEGORIZED_LABEL : ExpenseCategory::from($value)->label(),
                'color' => $value === self::UNCATEGORIZED_CATEGORY ? self::UNCATEGORIZED_COLOR : ExpenseCategory::from($value)->color(),
                'total_cents' => 0,
            ];
        }

        return $options;
    }

    /**
     * A lista traz so as categorias com gasto no mes: uma barra de zero nao
     * comunica nada e polui a legenda. O balde "sem categoria" segue a mesma
     * regra — so aparece quando ha algo nao classificado no mes — mas entra na
     * mesma soma e no mesmo arsort das categorias reais, e nao num ramo a
     * parte, para que a barra ordenada por valor continue batendo com o total
     * de verdade do mes.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{category: string, label: string, color: string, total_cents: int}>
     */
    private function categories(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $totals = [];

        foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
            $key = $category->value ?? self::UNCATEGORIZED_CATEGORY;
            $totals[$key] = ($totals[$key] ?? 0) + $amountCents;
        }

        arsort($totals);

        return array_map(
            fn (string $value, int $totalCents): array => [
                'category' => $value,
                'label' => $value === self::UNCATEGORIZED_CATEGORY ? self::UNCATEGORIZED_LABEL : ExpenseCategory::from($value)->label(),
                'color' => $value === self::UNCATEGORIZED_CATEGORY ? self::UNCATEGORIZED_COLOR : ExpenseCategory::from($value)->color(),
                'total_cents' => $totalCents,
            ],
            array_keys($totals),
            $totals,
        );
    }

    /**
     * O balde "sem categoria" entra em totals() sob a chave
     * self::UNCATEGORIZED_CATEGORY, junto das categorias reais: e assim que o
     * grafico empilhado de 12 meses recebe a altura certa mesmo quando parte
     * do mes ainda nao foi classificada, em vez de renderizar esse mes como se
     * fosse mais barato que os vizinhos.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{month: string, totals: array<string, int>}>
     */
    private function series(Collection $cash, Collection $installments, CarbonImmutable $seriesStart, int $months): array
    {
        return array_map(
            function (int $offset) use ($cash, $installments, $seriesStart): array {
                $month = $seriesStart->addMonths($offset);
                $totals = [];

                foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
                    $key = $category->value ?? self::UNCATEGORIZED_CATEGORY;
                    $totals[$key] = ($totals[$key] ?? 0) + $amountCents;
                }

                return ['month' => $month->format('Y-m'), 'totals' => $totals];
            },
            range(0, $months - 1),
        );
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @param  string  $search  termo ja normalizado pelo controller; vazio significa "sem busca"
     * @param  string  $sort  coluna de ordenacao (self::SORTABLE_COLUMNS)
     * @param  string  $direction  'asc' ou 'desc'
     * @return array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>
     */
    private function entries(Collection $cash, Collection $installments, CarbonImmutable $month, string $search = '', string $sort = 'valor', string $direction = 'desc'): array
    {
        $entries = [];

        foreach ($cash as $purchase) {
            if (! $purchase->billed_at->isSameMonth($month, true)) {
                continue;
            }

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'cash',
                'description' => $purchase->description,
                'amount_cents' => $purchase->amount_cents,
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'installment_label' => null,
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        foreach ($installments as $installment) {
            if (! $installment->due_date->isSameMonth($month, true)) {
                continue;
            }

            $purchase = $installment->purchase;

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'purchase',
                'description' => $purchase->description,
                'amount_cents' => $installment->amount_cents,
                'purchased_at' => $installment->due_date->toDateString(),
                'installment_label' => "{$installment->number}/{$purchase->installments_count}",
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => str_contains(mb_strtolower($entry['description']), $needle),
            ));
        }

        $signal = $direction === 'asc' ? 1 : -1;

        usort($entries, fn (array $a, array $b): int => $signal * match ($sort) {
            'descricao' => strnatcasecmp($a['description'], $b['description']),
            'cartao' => strnatcasecmp($a['card_name'], $b['card_name']),
            'data' => strcmp($a['purchased_at'], $b['purchased_at']),
            'categoria' => strnatcasecmp($this->categoryLabel($a['category']), $this->categoryLabel($b['category'])),
            default => $a['amount_cents'] <=> $b['amount_cents'],
        });

        return $entries;
    }

    /**
     * Rotulo usado so para ordenar. O prefixo do balde "sem categoria" e um
     * caractere alto de proposito: ordenar alfabeticamente colocaria "Sem
     * categoria" no meio da lista, e o que o usuario quer ver junto e o que
     * ainda falta classificar — no fim, num bloco so.
     */
    private function categoryLabel(?string $category): string
    {
        return $category === null
            ? '~~~'
            : ExpenseCategory::from($category)->label();
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     */
    private function uncategorizedCount(Collection $cash, Collection $installments, CarbonImmutable $month): int
    {
        $count = 0;

        foreach ($this->inMonth($cash, $installments, $month) as [$category]) {
            if ($category === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Contraparte em valor de uncategorizedCount(): a contagem sozinha nao diz
     * quanto dinheiro esta faltando classificar, e e essa cifra que o alerta
     * da tela e o balde "sem categoria" dos dois graficos precisam mostrar.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     */
    private function uncategorizedTotalCents(Collection $cash, Collection $installments, CarbonImmutable $month): int
    {
        $total = 0;

        foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
            if ($category === null) {
                $total += $amountCents;
            }
        }

        return $total;
    }

    /**
     * Achata os dois tipos numa lista de (categoria, valor) do mes pedido, que
     * e a unica forma em que as somas os enxergam.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{0: ExpenseCategory|null, 1: int}>
     */
    private function inMonth(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $rows = [];

        foreach ($cash as $purchase) {
            if ($purchase->billed_at->isSameMonth($month, true)) {
                $rows[] = [$purchase->category, $purchase->amount_cents];
            }
        }

        foreach ($installments as $installment) {
            if ($installment->due_date->isSameMonth($month, true)) {
                $rows[] = [$installment->purchase->category, $installment->amount_cents];
            }
        }

        return $rows;
    }
}
