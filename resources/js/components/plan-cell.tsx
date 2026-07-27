import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatBRL, parseBRLToCents } from '@/lib/currency';
import { cn } from '@/lib/utils';

type PlanCellProps = {
    url: string;
    valueCents: number;
    isExplicit: boolean;
    hint: string | null;
};

export function PlanCell({ url, valueCents, isExplicit, hint }: PlanCellProps) {
    const [draft, setDraft] = useState(() => formatBRL(valueCents));

    function commit() {
        const trimmed = draft.trim();
        const next = trimmed === '' ? null : parseBRLToCents(trimmed);

        if (next === valueCents && isExplicit) {
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

    return (
        <td className="p-0">
            <input
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                inputMode="numeric"
                aria-label={hint ?? undefined}
                className={cn(
                    'w-full bg-transparent px-3 py-2 text-right text-sm tabular-nums outline-none focus:bg-accent',
                    isExplicit ? 'text-foreground' : 'text-muted-foreground',
                )}
            />
            {hint && (
                <span className="block px-3 pb-1 text-right text-[11px] text-muted-foreground tabular-nums">
                    {hint}
                </span>
            )}
        </td>
    );
}
