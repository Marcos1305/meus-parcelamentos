<?php

namespace App\Http\Controllers;

use App\Actions\GenerateInstallments;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseNotesRequest;
use App\Models\Card;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $cards = $request->user()->cards()->orderBy('name')->get();

        if ($cards->isEmpty()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Cadastre um cartão antes de lançar uma compra.',
            ]);

            return to_route('cards.create');
        }

        return Inertia::render('purchases/create', [
            'cards' => $cards->map(fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ]),
        ]);
    }

    public function store(StorePurchaseRequest $request, GenerateInstallments $generateInstallments): RedirectResponse
    {
        DB::transaction(function () use ($request, $generateInstallments): void {
            $purchase = Purchase::create($request->validated());

            $generateInstallments->handle($purchase->load('card'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento registrado.']);

        return to_route('dashboard');
    }

    /**
     * A observacao mora na compra, nao na parcela: anotar "presente da Ana"
     * vale para as doze parcelas, nao para a de marco. Mesma regra ja adotada
     * para categoria.
     */
    public function updateNotes(UpdatePurchaseNotesRequest $request, Purchase $purchase): RedirectResponse
    {
        $purchase->update($request->validated());

        return back();
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        $purchase->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento removido.']);

        return back();
    }
}
