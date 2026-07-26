<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildFinanceTip;
use App\Actions\BuildMonthlyProjection;
use App\Enums\InstallmentStatus;
use App\Models\Card;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
        BuildFinanceTip $buildFinanceTip,
    ): Response {
        $user = $request->user();
        $filters = $this->filters($request);

        $installments = $this->query($user, $filters)
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
     * @return array{month: string, status: string, card: string}
     */
    private function filters(Request $request): array
    {
        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
        ];
    }

    /**
     * @param  array{month: string, status: string, card: string}  $filters
     * @return Builder<Installment>
     */
    private function query(User $user, array $filters): Builder
    {
        $query = Installment::query()
            ->forUser($user)
            ->with(['purchase.card']);

        if ($filters['month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filters['month']) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01');

            $query->whereBetween('due_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        if ($filters['card'] !== 'all') {
            $query->whereHas(
                'purchase',
                fn (Builder $purchases) => $purchases->where('card_id', (int) $filters['card']),
            );
        }

        $today = CarbonImmutable::now();

        match (InstallmentStatus::tryFrom($filters['status'])) {
            InstallmentStatus::Paid => $query->whereDate('due_date', '<', $today->toDateString()),
            InstallmentStatus::Upcoming => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            InstallmentStatus::Pending => $query->whereDate('due_date', '>', $today->endOfMonth()->toDateString()),
            null => null,
        };

        return $query;
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
