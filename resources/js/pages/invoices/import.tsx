import { Form, Head } from '@inertiajs/react';
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { CardOption } from '@/types';

export default function ImportInvoice({ cards }: { cards: CardOption[] }) {
    return (
        <>
            <Head title="Importar fatura" />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Importar fatura"
                    description="Envie o PDF da fatura para lançar os parcelamentos de uma vez"
                />

                <Card>
                    <CardContent className="pt-6">
                        <Form
                            {...InvoiceImportController.store.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invoice">
                                            PDF da fatura
                                        </Label>
                                        <Input
                                            id="invoice"
                                            name="invoice"
                                            type="file"
                                            accept="application/pdf"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Nubank, Mercado Pago, Itaú e Renner.
                                            O arquivo não é armazenado.
                                        </p>
                                        <InputError message={errors.invoice} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Senha do PDF (se houver)
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="password"
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="card_id">
                                            Cartão (opcional)
                                        </Label>
                                        <Select name="card_id">
                                            <SelectTrigger id="card_id">
                                                <SelectValue placeholder="Identificar pelo PDF" />
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

                                    <Button type="submit" disabled={processing}>
                                        Analisar fatura
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
