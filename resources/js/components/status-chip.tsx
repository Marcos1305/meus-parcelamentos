import { Badge } from '@/components/ui/badge';

const LABELS = {
    paid: 'Pago',
    upcoming: 'Próximo',
    pending: 'Pendente',
} as const;

const CLASSES = {
    paid: 'bg-secondary/10 text-secondary border-transparent',
    upcoming: 'bg-primary/20 text-status-upcoming border-transparent',
    pending: 'bg-destructive/10 text-destructive border-transparent',
} as const;

export function StatusChip({ status }: { status: keyof typeof LABELS }) {
    return (
        <Badge variant="outline" className={`rounded-full ${CLASSES[status]}`}>
            {LABELS[status]}
        </Badge>
    );
}
