import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBRL, formatBRLCompact, formatMonthLabel } from '@/lib/currency';
import type { IncomeCommitmentRow } from '@/types';

type CommitmentPoint = IncomeCommitmentRow & { label: string };

function CommitmentTooltip({
    active,
    payload,
}: {
    active?: boolean;
    payload?: { payload: CommitmentPoint }[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0].payload;
    const committedCents = point.card_cents + point.fixed_expense_cents;
    const balanceCents =
        point.income_cents === null
            ? null
            : point.income_cents - committedCents;

    return (
        <div className="rounded-lg border bg-background px-3 py-2 text-sm shadow-sm">
            <p className="mb-1 font-medium capitalize">
                {formatMonthLabel(point.month)}
            </p>
            <dl className="space-y-0.5">
                <TooltipRow
                    label="Cartões"
                    swatch="var(--series-1)"
                    value={formatBRL(point.card_cents)}
                />
                <TooltipRow
                    label="Despesas fixas"
                    swatch="var(--series-2)"
                    value={formatBRL(point.fixed_expense_cents)}
                />
                <TooltipRow
                    label="Entrada"
                    swatch="var(--foreground)"
                    value={
                        point.income_cents === null
                            ? 'não informada'
                            : formatBRL(point.income_cents)
                    }
                />
            </dl>
            {balanceCents !== null && point.income_cents !== null && (
                <p className="mt-1.5 border-t pt-1.5 tabular-nums">
                    {balanceCents >= 0 ? 'Sobra' : 'Falta'}{' '}
                    <span className="font-medium">
                        {formatBRL(Math.abs(balanceCents))}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        (
                        {Math.round(
                            (committedCents / point.income_cents) * 100,
                        )}
                        % comprometido)
                    </span>
                </p>
            )}
        </div>
    );
}

function TooltipRow({
    label,
    swatch,
    value,
}: {
    label: string;
    swatch: string;
    value: string;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt className="flex items-center gap-1.5 text-muted-foreground">
                <span
                    aria-hidden="true"
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: swatch }}
                />
                {label}
            </dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

/**
 * Barras empilhadas de fatura e despesas fixas contra a linha de entrada, na
 * mesma escala: o espaço entre o topo da barra e a linha é o que sobra. Mês
 * sem entrada informada fica sem ponto na linha, em vez de cair a zero.
 */
export function IncomeCommitmentChart({
    months,
}: {
    months: IncomeCommitmentRow[];
}) {
    const data: CommitmentPoint[] = months.map((row) => {
        const [year, monthNumber] = row.month.split('-');
        const shortLabel = formatMonthLabel(row.month).split(' de ')[0];

        return {
            ...row,
            label: monthNumber === '01' ? `${shortLabel}/${year}` : shortLabel,
        };
    });

    const maxValue = Math.max(
        1,
        ...data.map((point) =>
            Math.max(
                point.card_cents + point.fixed_expense_cents,
                point.income_cents ?? 0,
            ),
        ),
    );
    const yAxisWidth = Math.max(48, formatBRLCompact(maxValue).length * 7 + 8);
    const hasMissingIncome = data.some((point) => point.income_cents === null);

    return (
        <div>
            <ResponsiveContainer width="100%" height={240}>
                <ComposedChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} stroke="var(--border)" />
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        stroke="var(--muted-foreground)"
                        fontSize={12}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        width={yAxisWidth}
                        stroke="var(--muted-foreground)"
                        fontSize={12}
                        tickFormatter={(value: number) =>
                            formatBRLCompact(value)
                        }
                    />
                    <Tooltip
                        cursor={{ fill: 'var(--accent)' }}
                        content={<CommitmentTooltip />}
                    />
                    <Bar
                        dataKey="card_cents"
                        name="Cartões"
                        stackId="committed"
                        fill="var(--series-1)"
                        stroke="var(--card)"
                        strokeWidth={2}
                        maxBarSize={48}
                    />
                    <Bar
                        dataKey="fixed_expense_cents"
                        name="Despesas fixas"
                        stackId="committed"
                        fill="var(--series-2)"
                        stroke="var(--card)"
                        strokeWidth={2}
                        radius={[4, 4, 0, 0]}
                        maxBarSize={48}
                    />
                    <Line
                        dataKey="income_cents"
                        name="Entrada"
                        type="linear"
                        stroke="var(--foreground)"
                        strokeWidth={2}
                        strokeDasharray="6 4"
                        dot={{
                            r: 4,
                            fill: 'var(--foreground)',
                            stroke: 'var(--card)',
                            strokeWidth: 2,
                        }}
                        activeDot={false}
                        connectNulls={false}
                        isAnimationActive={false}
                    />
                </ComposedChart>
            </ResponsiveContainer>
            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 shrink-0 rounded-full bg-series-1"
                    />
                    Cartões
                </li>
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 shrink-0 rounded-full bg-series-2"
                    />
                    Despesas fixas
                </li>
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="w-3 shrink-0 border-t-2 border-dashed border-foreground"
                    />
                    Entrada
                </li>
            </ul>
            {hasMissingIncome && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Meses sem ponto na linha não têm entrada informada no
                    planejamento.
                </p>
            )}
        </div>
    );
}
