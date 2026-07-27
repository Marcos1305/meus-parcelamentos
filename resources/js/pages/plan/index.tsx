import { Head, Link } from '@inertiajs/react';
import { PlanGrid } from '@/components/plan-grid';
import { cn } from '@/lib/utils';
import planRoute from '@/routes/plan';
import type { AnnualPlan } from '@/types';

type PlanProps = {
    plan: AnnualPlan;
    years: number[];
};

export default function Plan({ plan, years }: PlanProps) {
    return (
        <>
            <Head title="Planejamento" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Gastos {plan.year}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Contas fixas, cartões e renda mês a mês
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {years.map((year) => (
                            <Link
                                key={year}
                                href={planRoute.index({
                                    query: { ano: String(year) },
                                })}
                                className={cn(
                                    'rounded-full border px-4 py-1.5 text-sm transition-colors',
                                    year === plan.year
                                        ? 'border-transparent bg-primary font-semibold text-primary-foreground'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary',
                                )}
                            >
                                {year}
                            </Link>
                        ))}
                    </div>
                </div>

                <PlanGrid plan={plan} />
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [{ title: 'Planejamento', href: planRoute.index() }],
};
