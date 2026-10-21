import { router } from '@inertiajs/react';
import { NotebookPen } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import purchases from '@/routes/purchases';

const MAX_NOTES_LENGTH = 1000;

type PurchaseNotesDialogProps = {
    purchaseId: number;
    description: string;
    notes: string | null;
};

export function PurchaseNotesDialog({
    purchaseId,
    description,
    notes,
}: PurchaseNotesDialogProps) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(notes ?? '');
    const [saving, setSaving] = useState(false);

    // O rascunho volta ao valor gravado sempre que o diálogo abre: fechar sem
    // salvar não pode deixar texto órfão esperando o próximo abrir.
    function toggle(next: boolean) {
        if (next) {
            setDraft(notes ?? '');
        }

        setOpen(next);
    }

    function save() {
        setSaving(true);

        router.patch(
            purchases.notes(purchaseId).url,
            { notes: draft },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={toggle}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={
                        notes
                            ? `Editar observação de ${description}`
                            : `Adicionar observação a ${description}`
                    }
                >
                    <NotebookPen
                        className={notes ? undefined : 'text-muted-foreground'}
                    />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Observação</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Textarea
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    rows={5}
                    maxLength={MAX_NOTES_LENGTH}
                    placeholder="Dividido com o João, ele paga metade em janeiro."
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => toggle(false)}
                        disabled={saving}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        Salvar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
