import { formatBRL } from '@/lib/currency';
import { cn } from '@/lib/utils';
import type { AnnualPlan } from '@/types';

const MONTH_LABEL = new Intl.DateTimeFormat('pt-BR', {
    month: 'short',
    timeZone: 'UTC',
});

function monthHeader(month: string): string {
    return MONTH_LABEL.format(new Date(`${month}-01T00:00:00Z`))
        .replace('.', '')
        .toUpperCase();
}

const HEAD_CELL =
    'px-3 py-2 text-right text-xs font-semibold whitespace-nowrap';
const BODY_CELL = 'px-3 py-2 text-right text-sm tabular-nums whitespace-nowrap';
const STICKY =
    'sticky left-0 z-10 bg-card px-3 py-2 text-left text-sm font-medium';

export function PlanGrid({ plan }: { plan: AnnualPlan }) {
    const incomeLines = plan.lines.filter((line) => line.kind === 'income');
    const expenseLines = plan.lines.filter((line) => line.kind === 'expense');

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full border-collapse">
                <thead className="bg-muted">
                    <tr>
                        <th
                            className={cn(STICKY, 'bg-muted text-xs uppercase')}
                        >
                            Categoria
                        </th>
                        {plan.months.map((month) => (
                            <th key={month} className={HEAD_CELL}>
                                {monthHeader(month)}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody className="divide-y">
                    {incomeLines.map((line) => (
                        <tr key={line.id}>
                            <td className={STICKY}>{line.name}</td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        line.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}

                    {plan.cards.map((card) => (
                        <tr key={`card-${card.id}`}>
                            <td className={STICKY}>
                                {card.name} • {card.last_four_digits}
                            </td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        card.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}

                    {expenseLines.map((line) => (
                        <tr key={line.id}>
                            <td className={STICKY}>{line.name}</td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        line.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>

                <tfoot className="border-t-2">
                    <tr className="bg-secondary/10">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            ENTRADA
                        </td>
                        {plan.months.map((month) => (
                            <td key={month} className={BODY_CELL}>
                                {formatBRL(plan.totals[month].income_cents)}
                            </td>
                        ))}
                    </tr>
                    <tr className="bg-destructive/10">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            SAÍDA
                        </td>
                        {plan.months.map((month) => (
                            <td key={month} className={BODY_CELL}>
                                {formatBRL(plan.totals[month].expense_cents)}
                            </td>
                        ))}
                    </tr>
                    <tr className="bg-primary/20">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            SALDO
                        </td>
                        {plan.months.map((month) => (
                            <td
                                key={month}
                                className={cn(
                                    BODY_CELL,
                                    'font-semibold',
                                    plan.totals[month].balance_cents < 0 &&
                                        'text-destructive',
                                )}
                            >
                                {formatBRL(plan.totals[month].balance_cents)}
                            </td>
                        ))}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
