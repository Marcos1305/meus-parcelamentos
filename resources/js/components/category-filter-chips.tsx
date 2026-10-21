import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { SpendingCategoryTotal } from '@/types';

/**
 * Chips de selecao multipla para o filtro de categoria da tela de gastos. A
 * cor de cada chip vem sempre de `option.color` (definida no servidor pelo
 * enum ExpenseCategory ou pelo balde "sem categoria"), nunca escrita aqui: o
 * chip so decide onde desenhar essa cor (a bolinha sempre, mais a borda
 * quando ativo).
 */
export function CategoryFilterChips({
    options,
    active,
    hrefFor,
}: {
    options: SpendingCategoryTotal[];
    active: string[];
    hrefFor: (category: string) => string;
}) {
    if (options.length === 0) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label="Filtrar por categoria"
        >
            {options.map((option) => {
                const isActive = active.includes(option.category);

                return (
                    <Link
                        key={option.category}
                        href={hrefFor(option.category)}
                        aria-pressed={isActive}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            isActive
                                ? 'bg-accent text-foreground'
                                : 'border-border bg-background text-muted-foreground hover:text-foreground',
                        )}
                        style={
                            isActive
                                ? { borderColor: option.color, borderWidth: 2 }
                                : undefined
                        }
                    >
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: option.color }}
                        />
                        {option.label}
                    </Link>
                );
            })}
        </div>
    );
}
