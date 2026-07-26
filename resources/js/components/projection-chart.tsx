import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
} from 'recharts';
import { formatBRL, formatMonthLabel } from '@/lib/currency';
import type { MonthlyProjectionRow } from '@/types';

type ChartPoint = {
    month: string;
    label: string;
    total: number;
};

function ProjectionTooltip({
    active,
    payload,
}: {
    active?: boolean;
    payload?: { payload: ChartPoint }[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0].payload;

    return (
        <div className="rounded-lg border bg-background px-3 py-2 text-sm shadow-sm">
            <p className="font-medium capitalize">
                {formatMonthLabel(point.month)}
            </p>
            <p className="text-muted-foreground tabular-nums">
                {formatBRL(point.total)}
            </p>
        </div>
    );
}

export function ProjectionChart({
    months,
}: {
    months: MonthlyProjectionRow[];
}) {
    const data: ChartPoint[] = months.map((row) => ({
        month: row.month,
        label: formatMonthLabel(row.month).split(' de ')[0],
        total: row.total_cents,
    }));

    return (
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
                <Tooltip
                    cursor={{ fill: 'var(--accent)' }}
                    content={<ProjectionTooltip />}
                />
                <Bar dataKey="total" fill="var(--chart-1)" radius={4} />
            </BarChart>
        </ResponsiveContainer>
    );
}
