import { Head, Link } from '@inertiajs/react';
import {
    ChevronRight,
    CreditCard,
    FileUp,
    ListChecks,
    Wallet,
} from 'lucide-react';
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
import { CardProjectionChart } from '@/components/card-projection-chart';
import { CategoryBreakdownChart } from '@/components/category-breakdown-chart';
import { EmptyState } from '@/components/empty-state';
import { IncomeCommitmentChart } from '@/components/income-commitment-chart';
import { PayoffChart } from '@/components/payoff-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import cards from '@/routes/cards';
import history from '@/routes/history';
import spending from '@/routes/spending';
import type {
    CardProjection,
    DashboardCardOption,
    DashboardSummary,
    IncomeCommitmentRow,
    MonthlyProjectionRow,
    PayoffPoint,
    SpendingCategoryTotal,
} from '@/types';

type DashboardProps = {
    summary: DashboardSummary;
    projection: MonthlyProjectionRow[];
    commitment: IncomeCommitmentRow[];
    payoff: PayoffPoint[];
    card_projection: CardProjection | null;
    categories: SpendingCategoryTotal[] | null;
    cards: DashboardCardOption[];
    selected_card_id: number | null;
    invoiceImportAvailable: boolean;
};

function cardChipLabel(card: DashboardCardOption): string {
    return `${card.name} • ${card.last_four_digits}`;
}

export default function Dashboard({
    summary,
    projection,
    commitment,
    payoff,
    card_projection: cardProjection,
    categories,
    cards: cardOptions,
    selected_card_id: selectedCardId,
    invoiceImportAvailable,
}: DashboardProps) {
    if (summary.cards_count === 0) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="p-4">
                    <EmptyState
                        title="Cadastre seu primeiro cartão"
                        description="Assim que houver um cartão, seus parcelamentos aparecem aqui com a projeção dos próximos meses."
                        action={
                            <Button asChild>
                                <Link href={cards.create()}>
                                    Cadastrar cartão
                                </Link>
                            </Button>
                        }
                    />
                </div>
            </>
        );
    }

    const selectedCard =
        cardOptions.find((card) => card.id === selectedCardId) ?? null;

    const historyCardFilter = selectedCard ? String(selectedCard.id) : 'all';

    const usedCents = summary.total_due_cents;
    const limitCents = summary.credit_limit_cents;
    const usedRatio = limitCents > 0 ? usedCents / limitCents : 0;

    const hasCommitment = commitment.some(
        (row) =>
            row.card_cents > 0 ||
            row.fixed_expense_cents > 0 ||
            row.income_cents !== null,
    );
    const showCardProjection =
        cardProjection !== null && cardProjection.series.length > 1;
    const showCategories = categories !== null && categories.length > 0;

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Visão Geral
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Sua saúde financeira nos próximos 6 meses
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={history.export().url}>
                                Exportar relatório
                            </a>
                        </Button>
                        {invoiceImportAvailable && (
                            <Button asChild>
                                <Link href={InvoiceImportController.create()}>
                                    <FileUp /> Importar fatura
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {cardOptions.length > 1 && (
                    <div
                        className="flex gap-2 overflow-x-auto pb-1"
                        aria-label="Filtrar por cartão"
                    >
                        <Link
                            href={dashboard()}
                            preserveScroll
                            aria-current={selectedCard ? undefined : 'true'}
                            className={cn(
                                'rounded-full border px-4 py-1.5 text-sm whitespace-nowrap transition-colors',
                                selectedCard
                                    ? 'border-border bg-card text-muted-foreground hover:border-primary'
                                    : 'border-transparent bg-primary font-semibold text-primary-foreground',
                            )}
                        >
                            Todos os cartões
                        </Link>
                        {cardOptions.map((card) => {
                            const isSelected = card.id === selectedCardId;

                            return (
                                <Link
                                    key={card.id}
                                    href={dashboard({
                                        query: { card: String(card.id) },
                                    })}
                                    preserveScroll
                                    aria-current={
                                        isSelected ? 'true' : undefined
                                    }
                                    className={cn(
                                        'rounded-full border px-4 py-1.5 text-sm whitespace-nowrap transition-colors',
                                        isSelected
                                            ? 'border-transparent bg-primary font-semibold text-primary-foreground'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary',
                                    )}
                                >
                                    {cardChipLabel(card)}
                                </Link>
                            );
                        })}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Card>
                                <CardHeader className="flex-row items-start justify-between gap-2 pb-2">
                                    <CardTitle className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Limite disponível
                                    </CardTitle>
                                    <CreditCard className="size-5 text-primary" />
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <p className="text-3xl font-bold tabular-nums">
                                        {formatBRL(limitCents - usedCents)}
                                    </p>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full bg-primary"
                                            style={{
                                                width: `${Math.min(usedRatio * 100, 100)}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground tabular-nums">
                                        Utilizado {formatBRL(usedCents)} de{' '}
                                        {formatBRL(limitCents)}
                                    </p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="flex-row items-start justify-between gap-2 pb-2">
                                    <CardTitle className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Parcelas em aberto
                                    </CardTitle>
                                    <ListChecks className="size-5 text-primary" />
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <p className="text-3xl font-bold tabular-nums">
                                        {summary.open_installments_count}
                                    </p>
                                    {summary.next_due_date && (
                                        <p className="text-xs text-status-upcoming tabular-nums">
                                            Próxima{' '}
                                            {formatBRL(summary.next_due_cents)}{' '}
                                            em{' '}
                                            {formatDate(summary.next_due_date)}
                                        </p>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="border-transparent bg-sidebar text-sidebar-foreground">
                                <CardHeader className="flex-row items-start justify-between gap-2 pb-2">
                                    <CardTitle className="text-xs tracking-wide text-sidebar-foreground/60 uppercase">
                                        Total devido
                                    </CardTitle>
                                    <Wallet className="size-5 text-sidebar-primary" />
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <p className="text-3xl font-bold tabular-nums">
                                        {formatBRL(summary.total_due_cents)}
                                    </p>
                                    {summary.last_due_date && (
                                        <span className="inline-block rounded-full bg-sidebar-foreground/10 px-3 py-1 text-xs capitalize">
                                            Finaliza em{' '}
                                            {formatMonthLabel(
                                                summary.last_due_date,
                                            )}
                                        </span>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {hasCommitment && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        {selectedCard
                                            ? `Comprometimento da entrada: ${cardChipLabel(selectedCard)}`
                                            : 'Comprometimento da entrada'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <IncomeCommitmentChart
                                        months={commitment}
                                    />
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <Card className="flex flex-col">
                        <CardHeader>
                            <CardTitle>Resumo mensal</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col">
                            <ul className="flex-1 space-y-1">
                                {projection.map((row, index) => (
                                    <li key={row.month}>
                                        <Link
                                            href={history.index({
                                                query: {
                                                    month: row.month,
                                                    card: historyCardFilter,
                                                },
                                            })}
                                            className="group flex items-center justify-between gap-3 rounded-md p-3 transition-colors hover:bg-muted"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium capitalize">
                                                        {formatMonthLabel(
                                                            row.month,
                                                        )}
                                                    </span>
                                                    {index === 0 && (
                                                        <Badge>Atual</Badge>
                                                    )}
                                                    {index === 1 && (
                                                        <Badge variant="secondary">
                                                            Próximo
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    Total estimado{' '}
                                                    {formatBRL(row.total_cents)}
                                                </p>
                                            </div>
                                            <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>

                            <Button variant="ghost" className="mt-4" asChild>
                                <Link
                                    href={history.index({
                                        query: {
                                            month: 'all',
                                            card: historyCardFilter,
                                        },
                                    })}
                                >
                                    Ver todos os meses
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                {(payoff.length > 0 ||
                    showCardProjection ||
                    showCategories) && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        {payoff.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Curva de quitação</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <PayoffChart points={payoff} />
                                </CardContent>
                            </Card>
                        )}

                        {showCardProjection && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Parcelas por cartão</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <CardProjectionChart
                                        projection={cardProjection}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {showCategories && (
                            <Card className="flex flex-col">
                                <CardHeader>
                                    <CardTitle>Gastos do mês</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-1 flex-col">
                                    <div className="flex-1">
                                        <CategoryBreakdownChart
                                            categories={categories}
                                        />
                                    </div>
                                    <Button
                                        variant="ghost"
                                        className="mt-4"
                                        asChild
                                    >
                                        <Link href={spending.index()}>
                                            Ver gastos
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {invoiceImportAvailable && (
                    <Card className="border-transparent bg-sidebar text-sidebar-foreground">
                        <CardContent className="flex flex-wrap items-center justify-between gap-6 pt-6">
                            <div className="max-w-xl space-y-2">
                                <h2 className="text-xl font-semibold">
                                    Importe sua fatura
                                </h2>
                                <p className="text-sm text-sidebar-foreground/70">
                                    Envie o PDF da fatura de Nubank, Mercado
                                    Pago, Itaú ou Renner. As compras parceladas
                                    são lidas automaticamente e você revisa
                                    antes de salvar.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={InvoiceImportController.create()}>
                                    <FileUp /> Importar fatura
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
