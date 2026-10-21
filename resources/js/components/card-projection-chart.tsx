import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBRL, formatBRLCompact, formatMonthLabel } from '@/lib/currency';
import type { CardProjection } from '@/types';

const OTHERS_KEY = 'outros';

const SERIES_COLORS = [
    'var(--series-1)',
    'var(--series-2)',
    'var(--series-3)',
    'var(--series-4)',
];

type Series = CardProjection['series'][number] & { color: string };

type ChartPoint = { month: string; label: string } & Record<
    string,
    number | string
>;

function CardProjectionTooltip({
    active,
    payload,
    series,
}: {
    active?: boolean;
    payload?: { dataKey: string; value: number; payload: ChartPoint }[];
    series: Series[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const rows = payload.filter((entry) => entry.value > 0);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border bg-background px-3 py-2 text-sm shadow-sm">
            <p className="mb-1 font-medium capitalize">
                {formatMonthLabel(payload[0].payload.month)}
            </p>
            <dl className="space-y-0.5">
                {rows.map((entry) => {
                    const item = series.find(
                        (candidate) => candidate.key === entry.dataKey,
                    );

                    return (
                        <div
                            key={entry.dataKey}
                            className="flex items-center justify-between gap-4"
                        >
                            <dt className="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ backgroundColor: item?.color }}
                                />
                                {item?.label ?? entry.dataKey}
                            </dt>
                            <dd className="tabular-nums">
                                {formatBRL(entry.value)}
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}

/**
 * Parcelas em aberto de cada mês, empilhadas por cartão. A ordem das séries
 * vem do servidor (maior total primeiro) e "Outros", cinza, fecha a pilha.
 */
export function CardProjectionChart({
    projection,
}: {
    projection: CardProjection;
}) {
    const series: Series[] = projection.series.map((item, index) => ({
        ...item,
        color:
            item.key === OTHERS_KEY
                ? 'var(--series-other)'
                : SERIES_COLORS[index],
    }));

    const data: ChartPoint[] = projection.months.map((row) => {
        const [year, monthNumber] = row.month.split('-');
        const shortLabel = formatMonthLabel(row.month).split(' de ')[0];

        return {
            month: row.month,
            label: monthNumber === '01' ? `${shortLabel}/${year}` : shortLabel,
            ...row.totals,
        };
    });

    const maxStackTotal = Math.max(
        1,
        ...projection.months.map((row) =>
            Object.values(row.totals).reduce((sum, value) => sum + value, 0),
        ),
    );
    const yAxisWidth = Math.max(
        48,
        formatBRLCompact(maxStackTotal).length * 7 + 8,
    );

    return (
        <div>
            <ResponsiveContainer width="100%" height={220}>
                <BarChart data={data} accessibilityLayer>
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
                        content={<CardProjectionTooltip series={series} />}
                    />
                    {series.map((item, index) => (
                        <Bar
                            key={item.key}
                            dataKey={item.key}
                            name={item.label}
                            stackId="total"
                            fill={item.color}
                            stroke="var(--card)"
                            strokeWidth={2}
                            maxBarSize={40}
                            radius={
                                index === series.length - 1
                                    ? [4, 4, 0, 0]
                                    : undefined
                            }
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                {series.map((item) => (
                    <li key={item.key} className="flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: item.color }}
                        />
                        {item.label}
                    </li>
                ))}
            </ul>
        </div>
    );
}
