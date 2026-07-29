import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
import AlertError from '@/components/alert-error';
import { CandidateStatusChip } from '@/components/candidate-status-chip';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatBRL, formatDate } from '@/lib/currency';
import type { InvoiceCandidate } from '@/types/parcelamentos';

type ReviewProps = {
    issuer: string;
    due_date: string;
    card: { id: number; name: string; last_four_digits: string };
    candidates: InvoiceCandidate[];
    cash_lines_count: number;
};

export default function ReviewInvoice({
    issuer,
    due_date,
    card,
    candidates,
    cash_lines_count,
}: ReviewProps) {
    const [selected, setSelected] = useState<boolean[]>(() =>
        candidates.map((candidate) => candidate.status === 'matched'),
    );
    const [dates, setDates] = useState<string[]>(() =>
        candidates.map((candidate) => candidate.purchased_at),
    );

    const [submittedOrder, setSubmittedOrder] = useState<number[]>([]);

    const form = useForm({});

    /**
     * As chaves de erro chegam como `lines.0.purchased_at`, indexadas pela
     * posição no payload, não pelo índice do candidato.
     */
    const errors = form.errors as Record<string, string | undefined>;
    const errorMessages = Object.values(errors).filter(
        (message): message is string => typeof message === 'string',
    );

    const errorForCandidate = (index: number): string | undefined => {
        const position = submittedOrder.indexOf(index);

        if (position === -1) {
            return undefined;
        }

        return (
            errors[`lines.${position}.purchased_at`] ??
            errors[`lines.${position}.index`]
        );
    };

    const submit = () => {
        const lines = candidates
            .map((_, index) => ({ index, purchased_at: dates[index] }))
            .filter((_, index) => selected[index]);

        setSubmittedOrder(lines.map((line) => line.index));

        form.transform(() => ({ lines }));

        form.post(InvoiceImportController.confirm.url(), {
            preserveScroll: true,
        });
    };

    const selectedCount = selected.filter(Boolean).length;

    return (
        <>
            <Head title="Revisar importação" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Revisar importação"
                    description={`${issuer} · vencimento ${formatDate(due_date)} · ${card.name} •••• ${card.last_four_digits}`}
                />

                {errorMessages.length > 0 && (
                    <AlertError
                        errors={errorMessages}
                        title="Não foi possível concluir a importação."
                    />
                )}

                {cash_lines_count > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {cash_lines_count === 1
                            ? '1 compra à vista desta fatura também será salva para análise de gastos.'
                            : `${cash_lines_count} compras à vista desta fatura também serão salvas para análise de gastos.`}
                    </p>
                )}

                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="p-3" />
                                    <th className="p-3">Item</th>
                                    <th className="p-3">Parcela</th>
                                    <th className="p-3">Valor da parcela</th>
                                    <th className="p-3">Total</th>
                                    <th className="p-3">Data da compra</th>
                                    <th className="p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {candidates.map((candidate, index) => (
                                    <tr key={index} className="border-b">
                                        <td className="p-3">
                                            <Checkbox
                                                checked={selected[index]}
                                                disabled={
                                                    candidate.status ===
                                                    'duplicate'
                                                }
                                                onCheckedChange={(checked) =>
                                                    setSelected((current) =>
                                                        current.map(
                                                            (value, i) =>
                                                                i === index
                                                                    ? checked ===
                                                                      true
                                                                    : value,
                                                        ),
                                                    )
                                                }
                                                aria-label={`Importar ${candidate.description}`}
                                            />
                                        </td>
                                        <td className="p-3">
                                            {candidate.description}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {candidate.installment_number}/
                                            {candidate.installments_count}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {formatBRL(
                                                candidate.installment_amount_cents,
                                            )}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {formatBRL(
                                                candidate.total_amount_cents,
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                type="date"
                                                value={dates[index]}
                                                className="w-40"
                                                onChange={(event) =>
                                                    setDates((current) =>
                                                        current.map(
                                                            (value, i) =>
                                                                i === index
                                                                    ? event
                                                                          .target
                                                                          .value
                                                                    : value,
                                                        ),
                                                    )
                                                }
                                            />
                                            <InputError
                                                className="mt-1"
                                                message={errorForCandidate(
                                                    index,
                                                )}
                                            />
                                        </td>
                                        <td className="p-3">
                                            <CandidateStatusChip
                                                status={candidate.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Button
                    onClick={submit}
                    disabled={selectedCount === 0 || form.processing}
                >
                    Importar {selectedCount}{' '}
                    {selectedCount === 1 ? 'compra' : 'compras'}
                </Button>
            </div>
        </>
    );
}
