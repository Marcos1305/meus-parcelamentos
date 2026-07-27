import { Badge } from '@/components/ui/badge';
import type { CandidateStatus } from '@/types/parcelamentos';

const LABELS: Record<CandidateStatus, string> = {
    matched: 'Pronto',
    duplicate: 'Já importado',
    divergent: 'Conferir data',
};

const CLASSES: Record<CandidateStatus, string> = {
    matched: 'bg-secondary/10 text-secondary border-transparent',
    duplicate: 'bg-muted text-muted-foreground border-transparent',
    divergent: 'bg-primary/20 text-status-upcoming border-transparent',
};

export function CandidateStatusChip({ status }: { status: CandidateStatus }) {
    return (
        <Badge variant="outline" className={`rounded-full ${CLASSES[status]}`}>
            {LABELS[status]}
        </Badge>
    );
}
