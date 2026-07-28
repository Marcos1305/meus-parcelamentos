import { router } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { LineColorOption } from '@/types';

type RowColorPickerProps = {
    url: string;
    color: string | null;
    colors: LineColorOption[];
    rowName: string;
};

export function RowColorPicker({
    url,
    color,
    colors,
    rowName,
}: RowColorPickerProps) {
    const selected = colors.find((option) => option.value === color) ?? null;

    function choose(next: string | null) {
        router.put(
            url,
            { color: next },
            { preserveScroll: true, preserveState: false },
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Cor de ${rowName}${
                        selected ? `: ${selected.label}` : ': sem cor'
                    }`}
                    className={cn(
                        'size-4 shrink-0 rounded-full border transition-transform hover:scale-110',
                        selected ? 'border-transparent' : 'border-dashed',
                    )}
                    style={
                        selected ? { backgroundColor: selected.hex } : undefined
                    }
                />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="w-44">
                <div className="grid grid-cols-6 gap-1 p-1">
                    {colors.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            title={option.label}
                            aria-label={option.label}
                            onClick={() => choose(option.value)}
                            className={cn(
                                'size-6 rounded-full transition-transform hover:scale-110',
                                option.value === color &&
                                    'ring-2 ring-foreground ring-offset-1 ring-offset-popover',
                            )}
                            style={{ backgroundColor: option.hex }}
                        />
                    ))}
                </div>

                <DropdownMenuSeparator />

                <DropdownMenuItem onSelect={() => choose(null)}>
                    Sem cor
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A faixa que marca a linha na coluna do nome. Sem cor escolhida ela não ocupa
 * espaço, para que a tabela continue igual para quem não usa o recurso.
 */
export function RowColorStripe({
    color,
    colors,
}: {
    color: string | null;
    colors: LineColorOption[];
}) {
    const selected = colors.find((option) => option.value === color) ?? null;

    if (selected === null) {
        return null;
    }

    return (
        <span
            aria-hidden="true"
            className="absolute inset-y-0 left-0 w-1"
            style={{ backgroundColor: selected.hex }}
        />
    );
}
