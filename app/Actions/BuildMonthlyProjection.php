<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildMonthlyProjection
{
    /**
     * Total em aberto por mes, comecando no mes corrente.
     *
     * @return array<int, array{month: string, total_cents: int}>
     */
    public function handle(User $user, int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $end = $start->addMonths($months)->subDay();

        $totals = Installment::query()
            ->forUser($user)
            ->open()
            ->whereDate('due_date', '<=', $end->toDateString())
            ->get(['due_date', 'amount_cents'])
            ->groupBy(fn (Installment $installment): string => $installment->due_date->format('Y-m'))
            ->map(fn (Collection $group): int => (int) $group->sum('amount_cents'));

        return array_map(
            function (int $offset) use ($start, $totals): array {
                $month = $start->addMonths($offset)->format('Y-m');

                return ['month' => $month, 'total_cents' => $totals->get($month, 0)];
            },
            range(0, $months - 1),
        );
    }
}
