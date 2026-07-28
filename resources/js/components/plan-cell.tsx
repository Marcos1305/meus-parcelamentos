import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { formatBRL, parseBRLToCents } from '@/lib/currency';
import { cn } from '@/lib/utils';

type PlanCellProps = {
    url: string;
    paidUrl: string | null;
    valueCents: number;
    isExplicit: boolean;
    isPaid: boolean;
    hint: string | null;
    label: string;
};

export function PlanCell({
    url,
    paidUrl,
    valueCents,
    isExplicit,
    isPaid,
    hint,
    label,
}: PlanCellProps) {
    const [draft, setDraft] = useState(() => formatBRL(valueCents));

    function commit() {
        const trimmed = draft.trim();

        if (trimmed !== '' && !/\d/.test(trimmed)) {
            setDraft(formatBRL(valueCents));

            return;
        }

        const next = trimmed === '' ? null : parseBRLToCents(trimmed);

        if (next === valueCents) {
            return;
        }

        if (next === null && !isExplicit) {
            setDraft(formatBRL(valueCents));

            return;
        }

        router.put(
            url,
            { amount_cents: next },
            {
                preserveScroll: true,
                preserveState: false,
                onError: () => setDraft(formatBRL(valueCents)),
            },
        );
    }

    function togglePaid() {
        if (paidUrl === null) {
            return;
        }

        router.put(
            paidUrl,
            { paid: !isPaid },
            { preserveScroll: true, preserveState: false },
        );
    }

    /**
     * O marcador fica à direita, colado no próprio valor. À esquerda da célula
     * ele encosta na coluna anterior e parece pertencer ao mês de antes.
     */
    return (
        <td className="group relative p-0">
            <input
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                inputMode="numeric"
                aria-label={label}
                className={cn(
                    'w-full bg-transparent py-2 pr-7 pl-3 text-right text-sm tabular-nums outline-none focus:bg-accent',
                    isExplicit ? 'text-foreground' : 'text-muted-foreground',
                    isPaid && 'text-muted-foreground line-through',
                )}
            />

            {paidUrl !== null && (
                <button
                    type="button"
                    onClick={togglePaid}
                    aria-label={
                        isPaid
                            ? `Desmarcar ${label} como paga`
                            : `Marcar ${label} como paga`
                    }
                    aria-pressed={isPaid}
                    className={cn(
                        'absolute top-1/2 right-1.5 size-4 -translate-y-1/2 rounded-full border transition-opacity',
                        isPaid
                            ? 'border-transparent bg-secondary text-secondary-foreground opacity-100'
                            : 'border-dashed opacity-0 group-hover:opacity-70 focus-visible:opacity-100',
                    )}
                >
                    {isPaid && <Check className="size-3" />}
                </button>
            )}

            {hint && (
                <span className="block px-3 pb-1 text-right text-[11px] text-muted-foreground tabular-nums">
                    {hint}
                </span>
            )}
        </td>
    );
}
