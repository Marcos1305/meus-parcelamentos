import { router } from '@inertiajs/react';
import { CalendarOff, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import planRoute from '@/routes/plan';

const MONTH_LABEL = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    timeZone: 'UTC',
});

function monthName(month: string): string {
    return MONTH_LABEL.format(new Date(`${month}-01T00:00:00Z`));
}

type MonthVisibilityMenuProps = {
    year: number;
    months: string[];
    hiddenMonths: string[];
    currentMonth: string;
};

export function MonthVisibilityMenu({
    year,
    months,
    hiddenMonths,
    currentMonth,
}: MonthVisibilityMenuProps) {
    function save(next: string[]) {
        router.put(
            planRoute.months.hidden(year).url,
            { months: next },
            { preserveScroll: true, preserveState: false },
        );
    }

    function toggle(month: string) {
        save(
            hiddenMonths.includes(month)
                ? hiddenMonths.filter((hidden) => hidden !== month)
                : [...hiddenMonths, month],
        );
    }

    const past = months.filter((month) => month < currentMonth);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    <CalendarOff />
                    Meses
                    {hiddenMonths.length > 0 && (
                        <span className="ml-1 rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums">
                            {months.length - hiddenMonths.length}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>Meses visíveis</DropdownMenuLabel>

                {months.map((month) => {
                    const isHidden = hiddenMonths.includes(month);

                    return (
                        <DropdownMenuItem
                            key={month}
                            onSelect={(event) => {
                                event.preventDefault();
                                toggle(month);
                            }}
                            className="capitalize"
                        >
                            <Check
                                className={cn(
                                    'size-4',
                                    isHidden && 'opacity-0',
                                )}
                            />
                            {monthName(month)}
                        </DropdownMenuItem>
                    );
                })}

                <DropdownMenuSeparator />

                <DropdownMenuItem
                    disabled={past.length === 0}
                    onSelect={() => save(past)}
                >
                    Esconder meses passados
                </DropdownMenuItem>
                <DropdownMenuItem
                    disabled={hiddenMonths.length === 0}
                    onSelect={() => save([])}
                >
                    Mostrar todos
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
