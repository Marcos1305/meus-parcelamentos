import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SortDirection = 'asc' | 'desc';

type SortableHeaderProps = {
    column: string;
    label: string;
    active: string;
    direction: SortDirection;
    href: (column: string) => string;
    align?: 'left' | 'right';
};

export function SortableHeader({
    column,
    label,
    active,
    direction,
    href,
    align = 'left',
}: SortableHeaderProps) {
    const isActive = active === column;
    const Icon = !isActive
        ? ChevronsUpDown
        : direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    // O nome acessível do link precisa dizer o que o clique faz, não só qual
    // é a coluna. Vai num `sr-only` em vez de num `aria-label` para que o
    // texto visível continue fazendo parte do nome — quem navega por voz
    // pede a coluna pelo rótulo que está vendo. Na coluna ativa dá para
    // nomear a direção (o clique inverte); nas demais não, porque a direção
    // inicial de cada coluna mora na tela, não neste componente.
    const actionHint = isActive
        ? direction === 'asc'
            ? 'ordenar em ordem decrescente'
            : 'ordenar em ordem crescente'
        : 'ordenar por esta coluna';

    return (
        <th
            className="p-0"
            aria-sort={
                isActive
                    ? direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : 'none'
            }
        >
            <Link
                href={href(column)}
                preserveState
                replace
                className={cn(
                    'flex w-full items-center gap-1 p-3 text-xs tracking-wide uppercase transition-colors hover:text-foreground',
                    isActive ? 'text-foreground' : 'text-muted-foreground',
                    align === 'right' && 'justify-end',
                )}
            >
                {label}
                <span className="sr-only">, {actionHint}</span>
                <Icon
                    aria-hidden="true"
                    className={cn('size-3', !isActive && 'opacity-40')}
                />
            </Link>
        </th>
    );
}
