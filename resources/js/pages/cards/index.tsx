import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, Pencil, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL } from '@/lib/currency';
import { create, destroy, edit, index } from '@/routes/cards';
import type { CardSummary } from '@/types';

export default function CardsIndex({ cards }: { cards: CardSummary[] }) {
    function remove(card: CardSummary) {
        if (
            !window.confirm(
                `Remover ${card.name}? Todas as compras e parcelas desse cartão serão apagadas.`,
            )
        ) {
            return;
        }

        router.delete(destroy(card.id).url, { preserveScroll: true });
    }

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
                                            onClick={() => remove(card)}
                                        >
                                            <Trash2 /> Remover
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

CardsIndex.layout = {
    breadcrumbs: [{ title: 'Meus Cartões', href: index() }],
};
