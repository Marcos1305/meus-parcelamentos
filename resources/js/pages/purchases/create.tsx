import { Form, Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/PurchaseController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatBRL, formatDate, parseBRLToCents } from '@/lib/currency';
import { buildSchedulePreview } from '@/lib/installments';
import { dashboard } from '@/routes';
import type { CardOption } from '@/types';

export default function CreatePurchase({ cards }: { cards: CardOption[] }) {
    const today = new Date().toISOString().slice(0, 10);

    const [cardId, setCardId] = useState(String(cards[0].id));
    const [totalCents, setTotalCents] = useState(0);
    const [count, setCount] = useState(1);
    const [purchasedAt, setPurchasedAt] = useState(today);

    const selectedCard =
        cards.find((card) => String(card.id) === cardId) ?? cards[0];

    const preview = useMemo(
        () =>
            buildSchedulePreview(
                totalCents,
                count,
                purchasedAt,
                selectedCard.closing_day,
                selectedCard.due_day,
            ),
        [totalCents, count, purchasedAt, selectedCard],
    );

    return (
        <>
            <Head title="Novo lançamento" />

            <div className="grid gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="space-y-6">
                    <Heading
                        title="Novo lançamento"
                        description="Registre uma compra parcelada ou à vista"
                    />

                    <Form
                        {...PurchaseController.store.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="card_id">Cartão</Label>
                                    <input
                                        type="hidden"
                                        name="card_id"
                                        value={cardId}
                                    />
                                    <Select
                                        value={cardId}
                                        onValueChange={setCardId}
                                    >
                                        <SelectTrigger id="card_id">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {cards.map((card) => (
                                                <SelectItem
                                                    key={card.id}
                                                    value={String(card.id)}
                                                >
                                                    {card.name} ••••{' '}
                                                    {card.last_four_digits}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.card_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">
                                        Descrição
                                    </Label>
                                    <Input
                                        id="description"
                                        name="description"
                                        required
                                        placeholder="MacBook Pro M2"
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="merchant">
                                        Estabelecimento (opcional)
                                    </Label>
                                    <Input
                                        id="merchant"
                                        name="merchant"
                                        placeholder="Apple Store - Shopping Morumbi"
                                    />
                                    <InputError message={errors.merchant} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="notes">
                                        Observação (opcional)
                                    </Label>
                                    <Textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        maxLength={1000}
                                        placeholder="Dividido com o João, ele paga metade em janeiro."
                                    />
                                    <InputError message={errors.notes} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="total">
                                            Valor total
                                        </Label>
                                        <input
                                            type="hidden"
                                            name="total_amount_cents"
                                            value={totalCents}
                                        />
                                        <Input
                                            id="total"
                                            inputMode="numeric"
                                            value={formatBRL(totalCents)}
                                            onChange={(event) =>
                                                setTotalCents(
                                                    parseBRLToCents(
                                                        event.target.value,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.total_amount_cents}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="installments_count">
                                            Parcelas
                                        </Label>
                                        <Input
                                            id="installments_count"
                                            name="installments_count"
                                            type="number"
                                            min={1}
                                            max={48}
                                            value={count}
                                            onChange={(event) =>
                                                setCount(
                                                    Number(event.target.value),
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.installments_count}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="purchased_at">
                                            Data da compra
                                        </Label>
                                        <Input
                                            id="purchased_at"
                                            name="purchased_at"
                                            type="date"
                                            max={today}
                                            value={purchasedAt}
                                            onChange={(event) =>
                                                setPurchasedAt(
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.purchased_at}
                                        />
                                    </div>
                                </div>

                                <Button type="submit" disabled={processing}>
                                    Lançar compra
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <Card className="h-fit">
                    <CardHeader>
                        <CardTitle>Parcelas que serão geradas</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {preview.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Informe o valor e o número de parcelas para ver
                                o cronograma.
                            </p>
                        ) : (
                            <ol className="divide-y text-sm">
                                {preview.map((row) => (
                                    <li
                                        key={row.number}
                                        className="flex items-baseline justify-between gap-4 py-2"
                                    >
                                        <span className="text-muted-foreground tabular-nums">
                                            {row.number}/{preview.length}
                                        </span>
                                        <span className="tabular-nums">
                                            {formatBRL(row.amount_cents)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDate(row.due_date)}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CreatePurchase.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Novo lançamento', href: '' },
    ],
};
