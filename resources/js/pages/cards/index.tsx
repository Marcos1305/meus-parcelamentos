import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, Pencil, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL, formatDate } from '@/lib/currency';
import { create, destroy, edit, index } from '@/routes/cards';
import history from '@/routes/history';
import type { CardOverview } from '@/types';

export default function CardsIndex({ cards }: { cards: CardOverview[] }) {
    function remove(card: CardOverview) {
        if (
            !window.confirm(
                `Remover ${card.name}? Todas as compras e parcelas desse cartão serão apagadas.`,
            )
        ) {
            return;
        }

        router.delete(destroy(card.id).url, { preserveScroll: true });
    }

    const totals = cards.reduce(
        (acc, card) => ({
            committed: acc.committed + card.committed_cents,
            limit: acc.limit + card.credit_limit_cents,
        }),
        { committed: 0, limit: 0 },
    );

    const ratio = totals.limit > 0 ? totals.committed / totals.limit : 0;

    return (
        <>
            <Head title="Meus Cartões" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Meus Cartões
                    </h1>
                    <Button asChild>
                        <Link href={create()}>Novo cartão</Link>
                    </Button>
                </div>

                {cards.length === 0 ? (
                    <EmptyState
                        title="Nenhum cartão cadastrado"
                        description="Cadastre seu primeiro cartão para começar a acompanhar seus parcelamentos."
                        action={
                            <Button asChild>
                                <Link href={create()}>Cadastrar cartão</Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Card>
                            <CardContent className="space-y-2 pt-6">
                                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                    Comprometido total
                                </p>
                                <p className="text-3xl font-bold tabular-nums">
                                    {formatBRL(totals.committed)}
                                </p>
                                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full bg-primary"
                                        style={{
                                            width: `${Math.min(ratio * 100, 100)}%`,
                                        }}
                                    />
                                </div>
                                <div className="flex justify-between text-xs text-muted-foreground tabular-nums">
                                    <span>
                                        Disponível{' '}
                                        {formatBRL(
                                            totals.limit - totals.committed,
                                        )}
                                    </span>
                                    <span>
                                        {Math.round(ratio * 100)}% de{' '}
                                        {formatBRL(totals.limit)}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {cards.map((card) => (
                                <Card key={card.id}>
                                    <CardHeader className="flex-row items-start justify-between gap-2">
                                        <div>
                                            <CardTitle>{card.name}</CardTitle>
                                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                                {[card.label, card.brand_label]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <CreditCard className="size-5 text-muted-foreground" />
                                    </CardHeader>

                                    <CardContent className="space-y-3">
                                        <p className="font-mono text-lg tabular-nums">
                                            •••• {card.last_four_digits}
                                        </p>

                                        <dl className="grid grid-cols-2 gap-2 text-sm">
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Disponível
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatBRL(
                                                        card.available_cents,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Comprometido
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatBRL(
                                                        card.committed_cents,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Limite
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatBRL(
                                                        card.credit_limit_cents,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Fecha / vence
                                                </dt>
                                                <dd className="tabular-nums">
                                                    dia {card.closing_day} / dia{' '}
                                                    {card.due_day}
                                                </dd>
                                            </div>
                                        </dl>

                                        {card.featured ? (
                                            <div className="space-y-2 rounded-md bg-muted/50 p-3">
                                                <div className="flex items-baseline justify-between gap-2">
                                                    <p className="text-sm font-medium">
                                                        {
                                                            card.featured
                                                                .description
                                                        }
                                                    </p>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {
                                                            card.featured
                                                                .current_number
                                                        }{' '}
                                                        de{' '}
                                                        {
                                                            card.featured
                                                                .installments_count
                                                        }
                                                    </span>
                                                </div>
                                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-background">
                                                    <div
                                                        className="h-full bg-primary"
                                                        style={{
                                                            width: `${(card.featured.paid_cents / card.featured.total_amount_cents) * 100}%`,
                                                        }}
                                                    />
                                                </div>
                                                <div className="flex justify-between text-xs tabular-nums">
                                                    <span className="text-muted-foreground">
                                                        Pago{' '}
                                                        {formatBRL(
                                                            card.featured
                                                                .paid_cents,
                                                        )}
                                                    </span>
                                                    <span>
                                                        Restante{' '}
                                                        {formatBRL(
                                                            card.featured
                                                                .remaining_cents,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>
                                        ) : (
                                            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                                                Sem parcelamentos em aberto.
                                            </p>
                                        )}

                                        {card.next_due_date && (
                                            <p className="text-xs text-muted-foreground">
                                                Próxima fatura{' '}
                                                {formatDate(card.next_due_date)}{' '}
                                                ·{' '}
                                                {formatBRL(card.next_due_cents)}
                                            </p>
                                        )}

                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(card.id)}>
                                                    <Pencil /> Editar
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={history.index({
                                                        query: {
                                                            card: String(
                                                                card.id,
                                                            ),
                                                            month: 'all',
                                                        },
                                                    })}
                                                >
                                                    Detalhes
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => remove(card)}
                                            >
                                                <Trash2 /> Remover
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

CardsIndex.layout = {
    breadcrumbs: [{ title: 'Meus Cartões', href: index() }],
};
