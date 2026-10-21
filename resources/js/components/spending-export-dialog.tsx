import { Download } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import spending from '@/routes/spending';

type SpendingExportDialogProps = {
    month: string;
};

function monthBounds(month: string): { from: string; until: string } {
    const [year, monthNumber] = month.split('-').map(Number);
    const lastDay = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();

    return {
        from: `${month}-01`,
        until: `${month}-${String(lastDay).padStart(2, '0')}`,
    };
}

/**
 * O download sai de um form GET nativo, e nao de uma visita do Inertia: a
 * resposta e um anexo, que o navegador baixa sem sair da pagina.
 */
export function SpendingExportDialog({ month }: SpendingExportDialogProps) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState('');
    const [until, setUntil] = useState('');

    // O periodo volta ao mes em tela sempre que o dialogo abre, para que o
    // padrao acompanhe a navegacao entre meses.
    function toggle(next: boolean) {
        if (next) {
            const bounds = monthBounds(month);
            setFrom(bounds.from);
            setUntil(bounds.until);
        }

        setOpen(next);
    }

    return (
        <Dialog open={open} onOpenChange={toggle}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Download />
                    Exportar CSV
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    method="get"
                    action={spending.export().url}
                    onSubmit={() => setOpen(false)}
                    className="space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>Exportar gastos</DialogTitle>
                        <DialogDescription>
                            Compras feitas no período, pela data da compra.
                            Compras parceladas saem numa linha só, com o valor
                            total.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="export-de">De</Label>
                            <Input
                                id="export-de"
                                name="de"
                                type="date"
                                value={from}
                                max={until}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                                required
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="export-ate">Até</Label>
                            <Input
                                id="export-ate"
                                name="ate"
                                type="date"
                                value={until}
                                min={from}
                                onChange={(event) =>
                                    setUntil(event.target.value)
                                }
                                required
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => toggle(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit">Exportar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
