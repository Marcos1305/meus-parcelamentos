<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;

class BuildDashboardSummary
{
    /**
     * @return array{
     *     cards_count: int,
     *     open_installments_count: int,
     *     total_due_cents: int,
     *     next_due_date: string|null,
     *     next_due_cents: int,
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
        ];
    }
}
