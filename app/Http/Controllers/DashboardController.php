<?php

namespace App\Http\Controllers;

use App\Actions\BuildCardProjection;
use App\Actions\BuildDashboardSummary;
use App\Actions\BuildIncomeCommitment;
use App\Actions\BuildMonthlyProjection;
use App\Actions\BuildPayoffCurve;
use App\Actions\BuildSpendingBreakdown;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
        BuildIncomeCommitment $buildIncomeCommitment,
        BuildPayoffCurve $buildPayoffCurve,
        BuildCardProjection $buildCardProjection,
        BuildSpendingBreakdown $buildSpendingBreakdown,
    ): Response {
        $user = $request->user();
        $card = $this->selectedCard($request, $user);

        return Inertia::render('dashboard', [
            'summary' => $buildDashboardSummary->handle($user, $card),
            'projection' => $buildMonthlyProjection->handle($user, $card),
            'commitment' => $buildIncomeCommitment->handle($user, $card),
            'payoff' => $buildPayoffCurve->handle($user, $card),
            'card_projection' => $card === null ? $buildCardProjection->handle($user) : null,
            'categories' => $card === null
                ? $buildSpendingBreakdown->handle($user, CarbonImmutable::now(), months: 1)['categories']
                : null,
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
