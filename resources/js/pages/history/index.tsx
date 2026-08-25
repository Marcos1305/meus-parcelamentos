import { Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ProjectionChart } from '@/components/projection-chart';
import { PurchaseNotesDialog } from '@/components/purchase-notes-dialog';
import { SortableHeader } from '@/components/sortable-header';
import type { SortDirection } from '@/components/sortable-header';
import { StatusChip } from '@/components/status-chip';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import history from '@/routes/history';
import { index } from '@/routes/history';
import purchases from '@/routes/purchases';
import type {
    CardOption,
    DashboardSummary,
    FinanceTip,
    HistoryFilters,
    HistoryRow,
    MonthlyProjectionRow,
    Paginated,
} from '@/types';

/**
 * Direção com que cada coluna abre no primeiro clique. Valor abre
 * decrescente porque "o mais caro" é o que se procura primeiro. As demais
 * abrem crescente: descrição e cartão porque A→Z é o que se espera de
 * texto, parcela pela mesma lógica numérica, e vencimento porque `asc` já
 * é o default de hoje da tela — mudar isso mudaria o comportamento de
 * abertura, não só de que coluna abre por padrão.
 */
const HISTORY_SORT_DEFAULTS: Record<string, SortDirection> = {
    description: 'asc',
    total: 'desc',
    card: 'asc',
    number: 'asc',
    due_date: 'asc',
};

type HistoryProps = {
    installments: Paginated<HistoryRow>;
    filters: HistoryFilters;
    summary: DashboardSummary;
    projection: MonthlyProjectionRow[];
    tip: FinanceTip | null;
    cards: Pick<CardOption, 'id' | 'name' | 'last_four_digits'>[];
    months: string[];
};

export default function History({
    installments,
    filters,
    summary,
    projection,
    tip,
    cards,
    months,
}: HistoryProps) {
    const [term, setTerm] = useState(filters.q);

    // O termo aparado é o que vai para a URL em qualquer caminho de
    // navegação: o `trim()` do PHP remove só " \t\n\r\0\x0B" enquanto o do JS
    // remove também espaço Unicode (NBSP e parentes, corriqueiros ao colar
    // nome de loja de uma página web). Mandar o valor bruto faria o servidor
    // devolver um `filters.q` que a guarda do debounce nunca igualaria, e o
    // efeito dispararia sem fim, uma requisição de página inteira a cada
    // 300ms.
    const trimmedTerm = term.trim();

    // `filters` chega com identidade nova a cada resposta do Inertia. Guardar
    // a versão corrente num ref deixa o efeito da busca depender só do termo
    // e do `q` do servidor: se dependesse do objeto inteiro, cada resposta
    // reagendaria o debounce e um desencontro pontual viraria loop.
    const filtersRef = useRef(filters);

    useEffect(() => {
        filtersRef.current = filters;
    });

    const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );

    // Debounce de 300ms: sem ele cada tecla vira uma requisição e a lista
    // pisca. `replace` evita empilhar uma entrada de histórico por letra.
    useEffect(() => {
        if (trimmedTerm === filters.q) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                index().url,
                { ...filtersRef.current, q: trimmedTerm },
                { preserveState: true, replace: true },
            );
        }, 300);

        searchTimer.current = timer;

        return () => clearTimeout(timer);
    }, [trimmedTerm, filters.q]);

    // Um clique num filtro ou num cabeçalho de coluna navega por conta
    // própria. O timer pendente, se sobrevivesse, dispararia 300ms depois
    // montado com o estado anterior ao clique e o Inertia cancelaria a visita
    // recém-iniciada — o que se perde é o clique do usuário. Cancelar no
    // evento `before` alcança também os `<Link>` de ordenação, que não têm
    // handler onde encaixar o cancelamento. Prefetch e escrita ficam de fora:
    // nenhum dos dois substitui a busca que o usuário acabou de digitar.
    useEffect(
        () =>
            router.on('before', (event) => {
                const { prefetch, method } = event.detail.visit;

                if (!prefetch && method === 'get') {
                    clearTimeout(searchTimer.current);
                }
            }),
        [],
    );

    function applyFilter(key: keyof HistoryFilters, value: string) {
        router.get(
            index().url,
            { ...filters, q: trimmedTerm, [key]: value },
            { preserveState: true, replace: true },
        );
    }

    function sortHref(column: string) {
        const direction: SortDirection =
            filters.sort === column
                ? filters.dir === 'asc'
                    ? 'desc'
                    : 'asc'
                : (HISTORY_SORT_DEFAULTS[column] ?? 'asc');

        return index({
            query: { ...filters, q: trimmedTerm, sort: column, dir: direction },
        }).url;
    }

    function removePurchase(row: HistoryRow) {
        const message = `Remover "${row.purchase.description}"? Isso apaga as ${row.purchase.installments_count} parcelas dessa compra.`;

        if (window.confirm(message)) {
            router.delete(purchases.destroy(row.purchase.id).url, {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title="Histórico de Parcelas" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Histórico de Parcelas
                    </h1>
                    <Button variant="outline" asChild>
                        <a href={history.export({ query: filters }).url}>
                            Exportar CSV
                        </a>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total em parcelas
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold tabular-nums">
                                {formatBRL(summary.total_due_cents)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Pago este mês
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold tabular-nums">
                                {formatBRL(summary.paid_this_month_cents)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Próximo vencimento
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold tabular-nums">
                                {formatBRL(summary.next_due_cents)}
                            </p>
                            {summary.next_due_date && (
                                <p className="text-xs text-muted-foreground">
                                    Vence em {formatDate(summary.next_due_date)}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Buscar por descrição, loja ou observação"
                        aria-label="Buscar parcelamentos"
                        className="w-[320px]"
                    />

                    <Select
                        value={filters.month}
                        onValueChange={(value) => applyFilter('month', value)}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os meses</SelectItem>
                            {months.map((month) => (
                                <SelectItem
                                    key={month}
                                    value={month}
                                    className="capitalize"
                                >
                                    {formatMonthLabel(month)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status}
                        onValueChange={(value) => applyFilter('status', value)}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os status</SelectItem>
                            <SelectItem value="paid">Pago</SelectItem>
                            <SelectItem value="upcoming">Próximo</SelectItem>
                            <SelectItem value="pending">Pendente</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.card}
                        onValueChange={(value) => applyFilter('card', value)}
                    >
                        <SelectTrigger className="w-[220px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                Todos os cartões
                            </SelectItem>
                            {cards.map((card) => (
                                <SelectItem
                                    key={card.id}
                                    value={String(card.id)}
                                >
                                    {card.name} •••• {card.last_four_digits}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {installments.data.length === 0 ? (
                    filters.q !== '' ? (
                        // Quem está pesquisando não quer criar um lançamento
                        // novo: quer o termo certo. Oferecer "Novo lançamento"
                        // aqui responde a uma pergunta que ninguém fez.
                        <EmptyState
                            title="Nenhuma parcela casa com a busca"
                            description="Ajuste o termo ou limpe a busca para ver a lista completa."
                        />
                    ) : (
                        <EmptyState
                            title="Nenhuma parcela encontrada"
                            description="Ajuste os filtros ou registre um novo lançamento."
                            action={
                                <Button asChild>
                                    <Link href={purchases.create()}>
                                        Novo lançamento
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b text-left">
                                    <tr>
                                        <SortableHeader
                                            column="description"
                                            label="Item"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="total"
                                            label="Valor total"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="card"
                                            label="Cartão"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="number"
                                            label="Parcela"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="due_date"
                                            label="Vencimento"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <th className="p-3 text-xs tracking-wide text-muted-foreground uppercase">
                                            Status
                                        </th>
                                        <th className="p-3 text-xs tracking-wide text-muted-foreground uppercase">
                                            <span className="sr-only">
                                                Ações
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {installments.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="p-3">
                                                <p className="font-medium">
                                                    {row.purchase.description}
                                                </p>
                                                {row.purchase.merchant && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {row.purchase.merchant}
                                                    </p>
                                                )}
                                                {row.purchase.notes && (
                                                    <p className="line-clamp-2 max-w-xs text-xs text-muted-foreground italic">
                                                        {row.purchase.notes}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {formatBRL(
                                                    row.purchase
                                                        .total_amount_cents,
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {row.card.name} ••••{' '}
                                                {row.card.last_four_digits}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {row.number}/
                                                {
                                                    row.purchase
                                                        .installments_count
                                                }
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatBRL(
                                                        row.amount_cents,
                                                    )}
                                                </span>
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {formatDate(row.due_date)}
                                            </td>
                                            <td className="p-3">
                                                <StatusChip
                                                    status={row.status}
                                                />
                                            </td>
                                            <td className="p-3">
                                                <div className="flex gap-1">
                                                    <PurchaseNotesDialog
                                                        purchaseId={
                                                            row.purchase.id
                                                        }
                                                        description={
                                                            row.purchase
                                                                .description
                                                        }
                                                        notes={
                                                            row.purchase.notes
                                                        }
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            removePurchase(row)
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {installments.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <p className="text-muted-foreground">
                            Mostrando {installments.data.length} de{' '}
                            {installments.total} parcelas
                        </p>
                        <div className="flex gap-1">
                            {installments.links.map((link, position) => (
                                <Button
                                    key={position}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={link.url === null}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Projeção de gastos</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ProjectionChart months={projection} />
                        </CardContent>
                    </Card>

                    {tip && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Dica de finanças</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm">
                                    Você tem <strong>{tip.count}</strong>{' '}
                                    {tip.count === 1
                                        ? 'parcelamento que termina'
                                        : 'parcelamentos que terminam'}{' '}
                                    este mês, liberando{' '}
                                    <strong>
                                        {formatBRL(tip.freed_cents)}
                                    </strong>{' '}
                                    a partir de{' '}
                                    <span className="capitalize">
                                        {formatMonthLabel(tip.next_month)}
                                    </span>
                                    .
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

History.layout = {
    breadcrumbs: [{ title: 'Histórico', href: index() }],
};
