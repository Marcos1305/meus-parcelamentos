<?php

namespace App\Http\Controllers;

use App\Enums\CardBrand;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Models\Card;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardController extends Controller
{
    public function index(Request $request): Response
    {
        $cards = $request->user()->cards()
            ->orderBy('name')
            ->get()
            ->map(fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'brand' => $card->brand->value,
                'brand_label' => $card->brand->label(),
                'label' => $card->label,
                'last_four_digits' => $card->last_four_digits,
                'credit_limit_cents' => $card->credit_limit_cents,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ]);

        return Inertia::render('cards/index', [
            'cards' => $cards,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('cards/create', [
            'brands' => $this->brandOptions(),
        ]);
    }

    public function store(StoreCardRequest $request): RedirectResponse
    {
        $request->user()->cards()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cartão cadastrado.']);

        return to_route('cards.index');
    }

    public function edit(Card $card): Response
    {
        return Inertia::render('cards/edit', [
            'card' => [
                'id' => $card->id,
                'name' => $card->name,
                'brand' => $card->brand->value,
                'label' => $card->label,
                'last_four_digits' => $card->last_four_digits,
                'credit_limit_cents' => $card->credit_limit_cents,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ],
            'brands' => $this->brandOptions(),
        ]);
    }

    public function update(UpdateCardRequest $request, Card $card): RedirectResponse
    {
        $card->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => 'Cartão atualizado. As parcelas já lançadas mantêm as datas antigas — rode installments:rebuild para recalculá-las.',
        ]);

        return to_route('cards.index');
    }

    public function destroy(Card $card): RedirectResponse
    {
        $card->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cartão removido.']);

        return to_route('cards.index');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function brandOptions(): array
    {
        return array_map(
            fn (CardBrand $brand): array => ['value' => $brand->value, 'label' => $brand->label()],
            CardBrand::cases(),
        );
    }
}
