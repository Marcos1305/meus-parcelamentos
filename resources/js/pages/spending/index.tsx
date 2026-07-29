import { Head, Link, router } from '@inertiajs/react';
import { CategoryBreakdownChart } from '@/components/category-breakdown-chart';
import { CategoryFilterChips } from '@/components/category-filter-chips';
import { CategoryTrendChart } from '@/components/category-trend-chart';
import { EmptyState } from '@/components/empty-state';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import spending from '@/routes/spending';
import type {
    ExpenseCategoryOption,
    SpendingBreakdown,
    SpendingEntry,
} from '@/types';
import { UNCATEGORIZED_CATEGORY_KEY } from '@/types/parcelamentos';

const NO_CATEGORY = UNCATEGORIZED_CATEGORY_KEY;

type SpendingProps = {
    breakdown: SpendingBreakdown;
    categories: ExpenseCategoryOption[];
};

function shiftMonth(month: string, delta: number): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, monthNumber - 1 + delta, 1));

    return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}`;
}

function categoryRoute(entry: SpendingEntry): string {
    return entry.kind === 'cash'
        ? spending.cashCategory(entry.id).url
        : spending.purchaseCategory(entry.id).url;
}

/**
 * Mes e filtro de categoria vivem juntos na url e cada um sobrevive a troca
 * do outro: navegar de mes preserva `categorias`, e marcar/desmarcar um chip
 * preserva `mes`. Omitir `categorias` quando a lista fica vazia (em vez de
 * mandar uma string vazia) mantem a url limpa no caso comum de "sem filtro".
 */
function spendingQuery(
    targetMonth: string,
    activeCategories: string[],
): Record<string, string> {
    return activeCategories.length > 0
        ? { mes: targetMonth, categorias: activeCategories.join(',') }
        : { mes: targetMonth };
}

export default function Spending({ breakdown, categories }: SpendingProps) {
    const {
        month,
        entries,
        uncategorized_count: uncategorizedCount,
        uncategorized_total_cents: uncategorizedTotalCents,
        uncategorized_color: uncategorizedColor,
        active_categories: activeCategories,
        available_categories: availableCategories,
    } = breakdown;

    const isFiltered = activeCategories.length > 0;
    const filteredTotalCents = breakdown.categories.reduce(
        (sum, category) => sum + category.total_cents,
        0,
    );
    // A trilha do grafico de 12 meses so mostra o balde "sem categoria"
    // quando ele nao foi excluido por um filtro ativo: caso contrario a serie
    // ficaria sempre zerada e so poluiria a legenda.
    const trendCategories = isFiltered
        ? categories.filter((category) =>
              activeCategories.includes(category.value),
          )
        : categories;
    const includeUncategorizedInTrend =
        !isFiltered || activeCategories.includes(NO_CATEGORY);

    function goToMonth(target: string) {
        return spending.index({
            query: spendingQuery(target, activeCategories),
        });
    }

    function toggleCategoryFilter(category: string): string {
        const next = activeCategories.includes(category)
            ? activeCategories.filter((value) => value !== category)
            : [...activeCategories, category];

        return spending.index({ query: spendingQuery(month, next) }).url;
    }

    function changeCategory(entry: SpendingEntry, value: string) {
        router.patch(
            categoryRoute(entry),
            { category: value === NO_CATEGORY ? null : value },
            { preserveScroll: true, preserveState: true },
        );
    }

    function categorizeAutomatically() {
        router.post(spending.categorize().url, {}, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Gastos" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Gastos
                    </h1>

                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={goToMonth(shiftMonth(month, -1))}
                                prefetch
                                aria-label="Mês anterior"
                            >
                                ‹
                            </Link>
                        </Button>
                        <span className="min-w-36 text-center text-sm font-medium capitalize">
                            {formatMonthLabel(month)}
                        </span>
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={goToMonth(shiftMonth(month, 1))}
                                prefetch
                                aria-label="Próximo mês"
                            >
                                ›
                            </Link>
                        </Button>
                    </div>
                </div>

                <CategoryFilterChips
                    options={availableCategories}
                    active={activeCategories}
                    hrefFor={toggleCategoryFilter}
                />

                {entries.length === 0 && (
                    <EmptyState
                        title={
                            isFiltered
                                ? 'Nenhum gasto nas categorias selecionadas'
                                : 'Nenhum gasto neste mês'
                        }
                        description={
                            isFiltered
                                ? 'Ajuste os filtros acima ou troque de mês para ver outros gastos.'
                                : 'A análise por categoria começa depois da primeira importação de fatura.'
                        }
                    />
                )}

                {entries.length > 0 && uncategorizedCount > 0 && (
                    <Alert>
                        <AlertTitle>
                            {uncategorizedCount === 1
                                ? '1 gasto sem categoria'
                                : `${uncategorizedCount} gastos sem categoria`}{' '}
                            ({formatBRL(uncategorizedTotalCents)})
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                Esse valor está contado nos gráficos abaixo,
                                mas ainda sem categoria própria. Classifique
                                manualmente na lista abaixo ou deixe a IA
                                sugerir uma categoria para cada um.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={categorizeAutomatically}
                            >
                                Categorizar automaticamente
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {/*
                    A série de 12 meses independe do mês selecionado ter
                    gastos: é o único contexto que continua verdadeiro num mês
                    vazio, então fica fora do ramo do estado vazio.
                */}
                <div
                    className={
                        entries.length > 0 ? 'grid gap-4 lg:grid-cols-2' : ''
                    }
                >
                    {entries.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Gastos por categoria</CardTitle>
                                {isFiltered && (
                                    <CardDescription>
                                        Subtotal das categorias selecionadas:{' '}
                                        {formatBRL(filteredTotalCents)}. Não é
                                        o total do mês.
                                    </CardDescription>
                                )}
                            </CardHeader>
                            <CardContent>
                                {breakdown.categories.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nenhum gasto categorizado neste mês
                                        ainda.
                                    </p>
                                ) : (
                                    <CategoryBreakdownChart
                                        categories={breakdown.categories}
                                    />
                                )}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Últimos 12 meses</CardTitle>
                            {isFiltered && (
                                <CardDescription>
                                    Evolução apenas das categorias
                                    selecionadas — não é o total do mês.
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent>
                            <CategoryTrendChart
                                months={breakdown.months}
                                categories={trendCategories}
                                uncategorizedColor={uncategorizedColor}
                                includeUncategorized={
                                    includeUncategorizedInTrend
                                }
                            />
                        </CardContent>
                    </Card>
                </div>

                {entries.length > 0 && (
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b text-left text-xs tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th className="p-3">Descrição</th>
                                        <th className="p-3">Cartão</th>
                                        <th className="p-3">Data</th>
                                        <th className="p-3">Valor</th>
                                        <th className="p-3">Categoria</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {entries.map((entry) => (
                                        <tr
                                            key={`${entry.kind}-${entry.id}-${entry.installment_label ?? 'unico'}`}
                                        >
                                            <td className="p-3">
                                                <p className="font-medium">
                                                    {entry.description}
                                                </p>
                                                {entry.installment_label && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Parcela{' '}
                                                        {
                                                            entry.installment_label
                                                        }
                                                    </p>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {entry.card_name}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {formatDate(entry.purchased_at)}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {formatBRL(entry.amount_cents)}
                                            </td>
                                            <td className="p-3">
                                                <Select
                                                    value={
                                                        entry.category ??
                                                        NO_CATEGORY
                                                    }
                                                    onValueChange={(value) =>
                                                        changeCategory(
                                                            entry,
                                                            value,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        size="sm"
                                                        className="w-44"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value={NO_CATEGORY}
                                                        >
                                                            Sem categoria
                                                        </SelectItem>
                                                        {categories.map(
                                                            (category) => (
                                                                <SelectItem
                                                                    key={
                                                                        category.value
                                                                    }
                                                                    value={
                                                                        category.value
                                                                    }
                                                                >
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="size-2.5 shrink-0 rounded-full"
                                                                        style={{
                                                                            backgroundColor:
                                                                                category.color,
                                                                        }}
                                                                    />
                                                                    {
                                                                        category.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Spending.layout = {
    breadcrumbs: [{ title: 'Gastos', href: spending.index() }],
};
