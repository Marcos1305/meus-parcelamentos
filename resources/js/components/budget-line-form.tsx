import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parseBRLToCents } from '@/lib/currency';
import planRoute from '@/routes/plan';

export function BudgetLineForm({ kind }: { kind: 'income' | 'expense' }) {
    const form = useForm({
        kind,
        name: '',
        default_amount_cents: 0,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(planRoute.lines.store().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'default_amount_cents'),
        });
    }

    return (
        <form onSubmit={submit} className="flex items-center gap-2 p-2">
            <Input
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder={kind === 'income' ? 'Nova entrada' : 'Nova conta'}
                className="h-8 w-48"
            />
            <Input
                defaultValue=""
                onChange={(event) =>
                    form.setData(
                        'default_amount_cents',
                        parseBRLToCents(event.target.value),
                    )
                }
                placeholder="Valor estimado"
                inputMode="numeric"
                className="h-8 w-36"
            />
            <Button type="submit" size="sm" disabled={form.processing}>
                <Plus /> Adicionar
            </Button>
        </form>
    );
}
