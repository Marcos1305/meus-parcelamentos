import { Head } from '@inertiajs/react';
import planRoute from '@/routes/plan';
import type { AnnualPlan } from '@/types';

export default function Plan({ plan }: { plan: AnnualPlan }) {
    return (
        <>
            <Head title="Planejamento" />
            <div className="p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Planejamento {plan.year}
                </h1>
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [{ title: 'Planejamento', href: planRoute.index() }],
};
