<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildMonthlyProjection;
use App\Models\Card;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
    ): Response {
        $user = $request->user();
        $card = $this->selectedCard($request, $user);

        return Inertia::render('dashboard', [
            'summary' => $buildDashboardSummary->handle($user, $card),
            'projection' => $buildMonthlyProjection->handle($user, $card),
            'cards' => $user->cards()->orderBy('name')->get()
                ->map(fn (Card $option): array => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'last_four_digits' => $option->last_four_digits,
                ]),
            'selected_card_id' => $card?->id,
        ]);
    }

    /**
     * O cartao do filtro. Um id de outro usuario nao existe para quem pergunta.
     */
    private function selectedCard(Request $request, User $user): ?Card
    {
        $id = $request->query('card');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $user->cards()->findOrFail($id);
    }
}
