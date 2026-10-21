import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBRL, formatBRLCompact, formatMonthLabel } from '@/lib/currency';
import type { PayoffPoint } from '@/types';

type ChartPoint = PayoffPoint & { label: string };

function PayoffTooltip({
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
                Início de {formatMonthLabel(point.month)}
            </p>
            <p className="text-muted-foreground tabular-nums">
                {point.remaining_cents === 0
                    ? 'Tudo quitado'
                    : `Falta pagar ${formatBRL(point.remaining_cents)}`}
            </p>
        </div>
    );
}

/**
 * Saldo devedor no início de cada mês, até o primeiro mês sem nada a pagar.
 */
export function PayoffChart({ points }: { points: PayoffPoint[] }) {
    const data: ChartPoint[] = points.map((point) => {
        const [year, monthNumber] = point.month.split('-');
        const shortLabel = formatMonthLabel(point.month).split(' de ')[0];

        return {
            ...point,
            label: monthNumber === '01' ? `${shortLabel}/${year}` : shortLabel,
        };
    });

    const maxValue = Math.max(1, ...data.map((point) => point.remaining_cents));
    const yAxisWidth = Math.max(48, formatBRLCompact(maxValue).length * 7 + 8);

    return (
        <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={data} accessibilityLayer>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis
                    dataKey="label"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    minTickGap={16}
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
                    tickFormatter={(value: number) => formatBRLCompact(value)}
                />
                <Tooltip
                    cursor={{ stroke: 'var(--muted-foreground)' }}
                    content={<PayoffTooltip />}
                />
                <Area
                    dataKey="remaining_cents"
                    type="stepAfter"
                    stroke="var(--series-1)"
                    strokeWidth={2}
                    fill="var(--series-1)"
                    fillOpacity={0.15}
                    activeDot={{
                        r: 4,
                        fill: 'var(--series-1)',
                        stroke: 'var(--card)',
                        strokeWidth: 2,
                    }}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
