import { Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { ProjectionChart } from '@/components/projection-chart';
import { PurchaseNotesDialog } from '@/components/purchase-notes-dialog';
import { StatusChip } from '@/components/status-chip';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    function applyFilter(key: keyof HistoryFilters, value: string) {
        router.get(
            index().url,
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
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
                ) : (
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b text-left text-xs tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th className="p-3">Item</th>
                                        <th className="p-3">Valor total</th>
                                        <th className="p-3">Cartão</th>
                                        <th className="p-3">Parcela</th>
                                        <th className="p-3">Vencimento</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3" />
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
                                            <td className="flex gap-1 p-3">
                                                <PurchaseNotesDialog
                                                    purchaseId={row.purchase.id}
                                                    description={
                                                        row.purchase.description
                                                    }
                                                    notes={row.purchase.notes}
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
