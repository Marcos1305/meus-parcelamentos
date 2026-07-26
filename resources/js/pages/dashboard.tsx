import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { ProjectionChart } from '@/components/projection-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import { dashboard } from '@/routes';
import cards from '@/routes/cards';
import purchases from '@/routes/purchases';
import type { DashboardSummary, MonthlyProjectionRow } from '@/types';

type DashboardProps = {
    summary: DashboardSummary;
    projection: MonthlyProjectionRow[];
};

export default function Dashboard({ summary, projection }: DashboardProps) {
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

    const hasFutureInstallments = projection.some((row) => row.total_cents > 0);

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Visão Geral
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Sua saúde financeira nos próximos 6 meses
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={purchases.create()}>Novo lançamento</Link>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total de cartões
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold tabular-nums">
                                {summary.cards_count}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Parcelas em aberto
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold tabular-nums">
                                {summary.open_installments_count}
                            </p>
                            {summary.next_due_date && (
                                <p className="text-xs text-muted-foreground">
                                    Próximo vencimento{' '}
                                    {formatDate(summary.next_due_date)} ·{' '}
                                    {formatBRL(summary.next_due_cents)}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Valor total devido
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold tabular-nums">
                                {formatBRL(summary.total_due_cents)}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {hasFutureInstallments && (
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <Card>
                            <CardHeader>
                                <CardTitle>Projeção 6 meses</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ProjectionChart months={projection} />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Resumo mensal</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="divide-y">
                                    {projection.map((row, index) => (
                                        <li
                                            key={row.month}
                                            className="flex items-center justify-between gap-3 py-3"
                                        >
                                            <div>
                                                <p className="text-sm font-medium capitalize">
                                                    {formatMonthLabel(
                                                        row.month,
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    {formatBRL(row.total_cents)}
                                                </p>
                                            </div>
                                            {index === 0 && (
                                                <Badge>Atual</Badge>
                                            )}
                                            {index === 1 && (
                                                <Badge variant="secondary">
                                                    Próximo
                                                </Badge>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
