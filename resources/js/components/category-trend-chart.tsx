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
import type { ExpenseCategoryOption, SpendingMonthTotals } from '@/types';
import { UNCATEGORIZED_CATEGORY_KEY } from '@/types/parcelamentos';

type TrendPoint = { month: string; label: string } & Record<
    string,
    number | string
>;

function TrendTooltip({
    active,
    payload,
    categories,
}: {
    active?: boolean;
    payload?: { dataKey: string; value: number; payload: TrendPoint }[];
    categories: ExpenseCategoryOption[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const rows = payload
        .filter((entry) => entry.value > 0)
        .sort((a, b) => b.value - a.value);

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
                    const category = categories.find(
                        (candidate) => candidate.value === entry.dataKey,
                    );

                    return (
                        <div
                            key={entry.dataKey}
                            className="flex items-center justify-between gap-4"
                        >
                            <dt className="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className="h-0.5 w-3 shrink-0"
                                    style={{
                                        backgroundColor: category?.color,
                                    }}
                                />
                                {category?.label ?? entry.dataKey}
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

function TrendLegend({ categories }: { categories: ExpenseCategoryOption[] }) {
    return (
        <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
            {categories.map((category) => (
                <li key={category.value} className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: category.color }}
                    />
                    {category.label}
                </li>
            ))}
        </ul>
    );
}

/**
 * Empilhado, treze categorias na mesma ordem em todos os meses: a ordem vem do
 * enum no servidor, que já foi escolhida para maximizar a distância
 * perceptual entre vizinhos. Reordenar por valor quebraria essa garantia e
 * "repintaria" categorias entre um mês e outro.
 *
 * O balde "sem categoria" é acrescentado depois dessas treze, sempre por
 * último: ele não faz parte da paleta categórica (é cinza neutro, vindo do
 * servidor) e não deve competir por posição no empilhado como se fosse mais
 * uma categoria — só entra para que a altura da barra do mês não fique menor
 * que o gasto real quando parte dele ainda não foi classificada.
 */
export function CategoryTrendChart({
    months,
    categories,
    uncategorizedColor,
}: {
    months: SpendingMonthTotals[];
    categories: ExpenseCategoryOption[];
    uncategorizedColor: string;
}) {
    const allSeries: ExpenseCategoryOption[] = [
        ...categories,
        {
            value: UNCATEGORIZED_CATEGORY_KEY,
            label: 'Sem categoria',
            color: uncategorizedColor,
        },
    ];

    const data: TrendPoint[] = months.map((row) => {
        const [year, monthNumber] = row.month.split('-');
        const shortLabel = formatMonthLabel(row.month).split(' de ')[0];

        return {
            month: row.month,
            // Doze meses sempre cruzam um ano; sem isso, janeiro do ano
            // anterior e janeiro do ano corrente ficam com o mesmo texto.
            label: monthNumber === '01' ? `${shortLabel}/${year}` : shortLabel,
            ...allSeries.reduce<Record<string, number>>((acc, category) => {
                acc[category.value] = row.totals[category.value] ?? 0;

                return acc;
            }, {}),
        };
    });

    const maxStackTotal = Math.max(
        1,
        ...data.map((point) =>
            allSeries.reduce(
                (sum, category) => sum + (Number(point[category.value]) || 0),
                0,
            ),
        ),
    );
    /**
     * Mesma heurística do gráfico de categorias: a largura do eixo vem do
     * maior rótulo que ele vai precisar desenhar, não de um número fixo.
     */
    const yAxisWidth = Math.max(
        48,
        formatBRLCompact(maxStackTotal).length * 7 + 8,
    );

    return (
        <div>
            <ResponsiveContainer width="100%" height={260}>
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
                        content={<TrendTooltip categories={allSeries} />}
                    />
                    {allSeries.map((category, index) => (
                        <Bar
                            key={category.value}
                            dataKey={category.value}
                            name={category.label}
                            stackId="total"
                            fill={category.color}
                            stroke="var(--card)"
                            strokeWidth={2}
                            radius={
                                index === allSeries.length - 1
                                    ? [4, 4, 0, 0]
                                    : undefined
                            }
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
            <TrendLegend categories={allSeries} />
        </div>
    );
}
