<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;

class BuildDashboardSummary
{
    /**
     * @return array{
     *     cards_count: int,
     *     open_installments_count: int,
     *     total_due_cents: int,
     *     next_due_date: string|null,
     *     next_due_cents: int,
     *     paid_this_month_cents: int,
     * }
     */
    public function handle(User $user): array
    {
        $open = Installment::query()->forUser($user)->open();

        $earliest = (clone $open)->min('due_date');

        $nextDueDate = $earliest === null ? null : substr((string) $earliest, 0, 10);

        $nextDueCents = $nextDueDate === null
            ? 0
            : (int) Installment::query()->forUser($user)->whereDate('due_date', $nextDueDate)->sum('amount_cents');

        return [
            'cards_count' => $user->cards()->count(),
            'open_installments_count' => (clone $open)->count(),
            'total_due_cents' => (int) (clone $open)->sum('amount_cents'),
            'next_due_date' => $nextDueDate,
            'next_due_cents' => $nextDueCents,
            'paid_this_month_cents' => (int) Installment::query()
                ->forUser($user)
                ->paid()
                ->whereBetween('due_date', [
                    CarbonImmutable::now()->startOfMonth()->toDateString(),
                    CarbonImmutable::now()->endOfMonth()->toDateString(),
                ])
                ->sum('amount_cents'),
        ];
    }
}
