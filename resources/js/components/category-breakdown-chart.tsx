import {
    Bar,
    BarChart,
    Cell,
    LabelList,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBRL } from '@/lib/currency';
import type { SpendingCategoryTotal } from '@/types';

type BreakdownTooltipPayload = { payload: SpendingCategoryTotal };

function BreakdownTooltip({
    active,
    payload,
}: {
    active?: boolean;
    payload?: BreakdownTooltipPayload[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const row = payload[0].payload;

    return (
        <div className="rounded-lg border bg-background px-3 py-2 text-sm shadow-sm">
            <p className="flex items-center gap-2 font-medium">
                <span
                    aria-hidden="true"
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: row.color }}
                />
                {row.label}
            </p>
            <p className="text-muted-foreground tabular-nums">
                {formatBRL(row.total_cents)}
            </p>
        </div>
    );
}

/**
 * Rótulo do eixo Y: a faixa de cor mora ao lado do nome da categoria, nunca
 * como legenda separada que o olho precisaria cruzar com a barra.
 */
function CategoryTick({
    x,
    y,
    payload,
    rows,
}: {
    x?: number;
    y?: number;
    payload?: { value: string };
    rows: SpendingCategoryTotal[];
}) {
    const row = rows.find((candidate) => candidate.category === payload?.value);

    if (!row || x === undefined || y === undefined) {
        return null;
    }

    return (
        <g transform={`translate(${x},${y})`}>
            <rect
                x={-160}
                y={-6}
                width={3}
                height={12}
                rx={1.5}
                fill={row.color}
            />
            <text
                x={-148}
                y={0}
                dy={4}
                textAnchor="start"
                className="fill-foreground text-xs"
            >
                {row.label}
            </text>
        </g>
    );
}

export function CategoryBreakdownChart({
    categories,
}: {
    categories: SpendingCategoryTotal[];
}) {
    const height = Math.max(categories.length * 36, 120);
    const maxValue = Math.max(1, ...categories.map((row) => row.total_cents));
    /**
     * Espaço para o valor impresso na ponta da barra. Estimado a partir do
     * maior valor do mês: sem isso, um total de cinco dígitos (R$ 10.000+)
     * corta o rótulo em vez de deixá-lo respirar fora da barra.
     */
    const rightMargin = Math.max(48, formatBRL(maxValue).length * 7 + 16);

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart
                data={categories}
                layout="vertical"
                margin={{ top: 4, right: rightMargin, bottom: 4, left: 0 }}
                accessibilityLayer
                barCategoryGap={8}
            >
                <XAxis type="number" hide domain={[0, maxValue]} />
                <YAxis
                    dataKey="category"
                    type="category"
                    tickLine={false}
                    axisLine={false}
                    width={160}
                    tick={<CategoryTick rows={categories} />}
                />
                <Tooltip
                    cursor={{ fill: 'var(--accent)' }}
                    content={<BreakdownTooltip />}
                />
                <Bar dataKey="total_cents" radius={4} maxBarSize={20}>
                    {categories.map((row) => (
                        <Cell key={row.category} fill={row.color} />
                    ))}
                    <LabelList
                        dataKey="total_cents"
                        position="right"
                        className="fill-foreground text-xs tabular-nums"
                        formatter={(value) =>
                            typeof value === 'number' ? formatBRL(value) : ''
                        }
                    />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
