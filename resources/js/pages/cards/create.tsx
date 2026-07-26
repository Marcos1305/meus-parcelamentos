import { Head } from '@inertiajs/react';
import CardController from '@/actions/App/Http/Controllers/CardController';
import Heading from '@/components/heading';
import { index } from '@/routes/cards';
import type { CardBrandOption } from '@/types';
import { CardForm } from './card-form';

export default function CreateCard({ brands }: { brands: CardBrandOption[] }) {
    return (
        <>
            <Head title="Novo cartão" />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Novo cartão"
                    description="Cadastre um cartão para começar a lançar parcelamentos"
                />
                <CardForm
                    action={CardController.store.form()}
                    brands={brands}
                    submitLabel="Cadastrar cartão"
                />
            </div>
        </>
    );
}

CreateCard.layout = {
    breadcrumbs: [
        { title: 'Meus Cartões', href: index() },
        { title: 'Novo cartão', href: '' },
    ],
};
