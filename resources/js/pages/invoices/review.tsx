import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CandidateStatusChip } from '@/components/candidate-status-chip';
import Heading from '@/components/heading';
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
};

export default function ReviewInvoice({
    issuer,
    due_date,
    card,
    candidates,
}: ReviewProps) {
    const [selected, setSelected] = useState<boolean[]>(() =>
        candidates.map((candidate) => candidate.status === 'matched'),
    );
    const [dates, setDates] = useState<string[]>(() =>
        candidates.map((candidate) => candidate.purchased_at),
    );

    const form = useForm({});

    const submit = () => {
        form.transform(() => ({
            lines: candidates
                .map((_, index) => ({ index, purchased_at: dates[index] }))
                .filter((_, index) => selected[index]),
        }));

        form.post('/importar/confirmar');
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
