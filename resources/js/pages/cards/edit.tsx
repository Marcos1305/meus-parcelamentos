import { Head } from '@inertiajs/react';
import CardController from '@/actions/App/Http/Controllers/CardController';
import Heading from '@/components/heading';
import { index } from '@/routes/cards';
import type { CardBrandOption, CardFormValues } from '@/types';
import { CardForm } from './card-form';

export default function EditCard({
    card,
    brands,
}: {
    card: CardFormValues;
    brands: CardBrandOption[];
}) {
    return (
        <>
            <Head title={`Editar ${card.name}`} />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Editar cartão"
                    description="Alterar fechamento ou vencimento não recalcula parcelas já lançadas"
                />
                <CardForm
                    action={CardController.update.form({ card: card.id! })}
                    brands={brands}
                    card={card}
                    submitLabel="Salvar alterações"
                />
            </div>
        </>
    );
}

EditCard.layout = {
    breadcrumbs: [
        { title: 'Meus Cartões', href: index() },
        { title: 'Editar', href: '' },
    ],
};
