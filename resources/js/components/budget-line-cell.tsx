import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Trash2 } from 'lucide-react';
import { useState } from 'react';
import planRoute from '@/routes/plan';
import type { PlanLineRow } from '@/types';

type BudgetLineCellProps = {
    line: PlanLineRow;
    isFirst: boolean;
    isLast: boolean;
};

export function BudgetLineCell({ line, isFirst, isLast }: BudgetLineCellProps) {
    const [name, setName] = useState(line.name);

    function patch(changes: Partial<Pick<PlanLineRow, 'name' | 'position'>>) {
        router.patch(
            planRoute.lines.update(line.id).url,
            {
                name: line.name,
                default_amount_cents: line.default_amount_cents,
                position: line.position,
                ...changes,
            },
            { preserveScroll: true, onError: () => setName(line.name) },
        );
    }

    function archive() {
        if (
            !window.confirm(
                `Arquivar ${line.name}? Os valores já lançados são preservados.`,
            )
        ) {
            return;
        }

        router.delete(planRoute.lines.destroy(line.id).url, {
            preserveScroll: true,
        });
    }

    return (
        <div className="flex items-center gap-1">
            <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                onBlur={() => name !== line.name && patch({ name })}
                aria-label={`Nome da linha ${line.name}`}
                className="w-40 bg-transparent text-sm font-medium outline-none focus:bg-accent"
            />
            <button
                type="button"
                onClick={() => patch({ position: line.position - 1 })}
                disabled={isFirst}
                className="text-muted-foreground hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
                aria-label={`Subir ${line.name}`}
            >
                <ChevronUp className="size-4" />
            </button>
            <button
                type="button"
                onClick={() => patch({ position: line.position + 1 })}
                disabled={isLast}
                className="text-muted-foreground hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
                aria-label={`Descer ${line.name}`}
            >
                <ChevronDown className="size-4" />
            </button>
            <button
                type="button"
                onClick={archive}
                className="text-muted-foreground hover:text-destructive"
                aria-label={`Arquivar ${line.name}`}
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}
