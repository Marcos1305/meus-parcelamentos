import { Badge } from '@/components/ui/badge';

const LABELS = {
    paid: 'Pago',
    upcoming: 'Próximo',
    pending: 'Pendente',
} as const;

const CLASSES = {
    paid: 'bg-secondary/15 text-secondary-foreground border-secondary/30',
    upcoming: 'bg-accent/15 text-accent-foreground border-accent/30',
    pending: 'bg-muted text-muted-foreground border-border',
} as const;

export function StatusChip({ status }: { status: keyof typeof LABELS }) {
    return (
        <Badge variant="outline" className={`rounded-full ${CLASSES[status]}`}>
            {LABELS[status]}
        </Badge>
    );
}
