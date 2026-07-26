import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBRL, parseBRLToCents } from '@/lib/currency';
import type { CardBrandOption, CardFormValues } from '@/types';

type CardFormProps = {
    action: Record<string, unknown>;
    brands: CardBrandOption[];
    card?: CardFormValues;
    submitLabel: string;
};

export function CardForm({ action, brands, card, submitLabel }: CardFormProps) {
    const [limitCents, setLimitCents] = useState(card?.credit_limit_cents ?? 0);
    const [brand, setBrand] = useState(card?.brand ?? brands[0].value);

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Apelido do cartão</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={card?.name}
                            required
                            placeholder="Nubank"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="brand">Bandeira</Label>
                            <input type="hidden" name="brand" value={brand} />
                            <Select value={brand} onValueChange={setBrand}>
                                <SelectTrigger id="brand">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {brands.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.brand} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="label">Variante (opcional)</Label>
                            <Input
                                id="label"
                                name="label"
                                defaultValue={card?.label ?? ''}
                                placeholder="Platinum"
                            />
                            <InputError message={errors.label} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="last_four_digits">
                                Últimos 4 dígitos
                            </Label>
                            <Input
                                id="last_four_digits"
                                name="last_four_digits"
                                defaultValue={card?.last_four_digits}
                                inputMode="numeric"
                                maxLength={4}
                                required
                                placeholder="8291"
                            />
                            <InputError message={errors.last_four_digits} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="credit_limit">Limite total</Label>
                            <input
                                type="hidden"
                                name="credit_limit_cents"
                                value={limitCents}
                            />
                            <Input
                                id="credit_limit"
                                inputMode="numeric"
                                value={formatBRL(limitCents)}
                                onChange={(event) =>
                                    setLimitCents(
                                        parseBRLToCents(event.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.credit_limit_cents} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="closing_day">
                                Dia de fechamento
                            </Label>
                            <Input
                                id="closing_day"
                                name="closing_day"
                                type="number"
                                min={1}
                                max={31}
                                defaultValue={card?.closing_day ?? 1}
                                required
                            />
                            <InputError message={errors.closing_day} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="due_day">Dia de vencimento</Label>
                            <Input
                                id="due_day"
                                name="due_day"
                                type="number"
                                min={1}
                                max={31}
                                defaultValue={card?.due_day ?? 10}
                                required
                            />
                            <InputError message={errors.due_day} />
                        </div>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Compras feitas depois do dia de fechamento entram na
                        fatura do mês seguinte.
                    </p>

                    <Button type="submit" disabled={processing}>
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
