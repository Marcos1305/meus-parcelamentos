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
                    aria-label={`Cor de ${rowName}${color ? `: ${color}` : ': sem cor'}`}
                    className={cn(
                        'size-4 shrink-0 rounded-full border transition-transform hover:scale-110',
                        color ? 'border-transparent' : 'border-dashed',
                    )}
                    style={color ? { backgroundColor: color } : undefined}
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
                            onClick={() => choose(option.hex)}
                            className={cn(
                                'size-6 rounded-full transition-transform hover:scale-110',
                                option.hex === color &&
                                    'ring-2 ring-foreground ring-offset-1 ring-offset-popover',
                            )}
                            style={{ backgroundColor: option.hex }}
                        />
                    ))}
                </div>

                <DropdownMenuSeparator />

                <label className="flex cursor-pointer items-center gap-2 px-2 py-1.5 text-sm hover:bg-accent">
                    <input
                        type="color"
                        value={color ?? '#888888'}
                        onChange={(event) => choose(event.target.value)}
                        className="size-6 cursor-pointer rounded border-0 bg-transparent p-0"
                        aria-label={`Escolher outra cor para ${rowName}`}
                    />
                    Outra cor…
                </label>

                <DropdownMenuSeparator />

                <DropdownMenuItem onSelect={() => choose(null)}>
                    Sem cor
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Alfa do fundo da linha, em hexadecimal: 14%. Baixo o bastante para não
 * competir com o cinza de valor herdado nem com o vermelho de saldo negativo,
 * que são os sinais que a tabela precisa preservar.
 */
const ROW_TINT_ALPHA = '24';

/**
 * O fundo da linha inteira. Devolve `undefined` sem cor escolhida, para que a
 * linha continue exatamente como era para quem não usa o recurso.
 */
export function rowTint(color: string | null): string | undefined {
    return color ? `${color}${ROW_TINT_ALPHA}` : undefined;
}

/**
 * O fundo e a faixa dentro da coluna fixa do nome.
 *
 * A célula fixa precisa de fundo opaco, senão o conteúdo das colunas de mês
 * passa por baixo dela na rolagem horizontal. Por isso o tom entra como uma
 * camada por cima desse fundo, em vez de substituí-lo.
 */
export function RowColorLayer({ color }: { color: string | null }) {
    if (color === null) {
        return null;
    }

    return (
        <>
            <span
                aria-hidden="true"
                className="absolute inset-0"
                style={{ backgroundColor: `${color}${ROW_TINT_ALPHA}` }}
            />
            <span
                aria-hidden="true"
                className="absolute inset-y-0 left-0 w-1"
                style={{ backgroundColor: color }}
            />
        </>
    );
}
