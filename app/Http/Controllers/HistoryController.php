<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildFinanceTip;
use App\Actions\BuildHistoryQuery;
use App\Actions\BuildMonthlyProjection;
use App\Models\Card;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(
        Request $request,
        BuildHistoryQuery $buildHistoryQuery,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
        BuildFinanceTip $buildFinanceTip,
    ): Response {
        $user = $request->user();
        $filters = $buildHistoryQuery->filters($request);

        $installments = $buildHistoryQuery->handle($user, $filters)
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Installment $installment): array => [
                'id' => $installment->id,
                'number' => $installment->number,
                'amount_cents' => $installment->amount_cents,
                'due_date' => $installment->due_date->toDateString(),
                'status' => $installment->status->value,
                'purchase' => [
                    'id' => $installment->purchase->id,
                    'description' => $installment->purchase->description,
                    'merchant' => $installment->purchase->merchant,
                    'total_amount_cents' => $installment->purchase->total_amount_cents,
                    'installments_count' => $installment->purchase->installments_count,
                ],
                'card' => [
                    'name' => $installment->purchase->card->name,
                    'last_four_digits' => $installment->purchase->card->last_four_digits,
                ],
            ]);

        return Inertia::render('history/index', [
            'installments' => $installments,
            'filters' => $filters,
            'summary' => $buildDashboardSummary->handle($user),
            'projection' => $buildMonthlyProjection->handle($user),
            'tip' => $buildFinanceTip->handle($user),
            'cards' => $user->cards()->orderBy('name')->get()
                ->map(fn (Card $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'last_four_digits' => $card->last_four_digits,
                ]),
            'months' => $this->monthOptions($user),
        ]);
    }

    /**
     * Meses que efetivamente tem parcelas, para popular o filtro.
     *
     * @return array<int, string>
     */
    private function monthOptions(User $user): array
    {
        return Installment::query()
            ->forUser($user)
            ->orderBy('due_date')
            ->pluck('due_date')
            ->map(fn (CarbonImmutable $date): string => $date->format('Y-m'))
            ->unique()
            ->values()
            ->all();
    }
}
