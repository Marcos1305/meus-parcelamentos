<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BuildDashboardSummary
{
    /**
     * @return array{
     *     cards_count: int,
     *     open_installments_count: int,
     *     total_due_cents: int,
     *     credit_limit_cents: int,
     *     next_due_date: string|null,
     *     next_due_cents: int,
     *     last_due_date: string|null,
     *     paid_this_month_cents: int,
     * }
     */
    public function handle(User $user, ?Card $card = null): array
    {
        $earliest = $this->open($user, $card)->min('due_date');
        $latest = $this->open($user, $card)->max('due_date');

        $nextDueDate = $earliest === null ? null : substr((string) $earliest, 0, 10);

        return [
            'cards_count' => $user->cards()->count(),
            'open_installments_count' => $this->open($user, $card)->count(),
            'total_due_cents' => (int) $this->open($user, $card)->sum('amount_cents'),
            'credit_limit_cents' => $card === null
                ? (int) $user->cards()->sum('credit_limit_cents')
                : $card->credit_limit_cents,
            'next_due_date' => $nextDueDate,
            'next_due_cents' => $nextDueDate === null
                ? 0
                : (int) $this->open($user, $card)->whereDate('due_date', $nextDueDate)->sum('amount_cents'),
            'last_due_date' => $latest === null ? null : substr((string) $latest, 0, 10),
            'paid_this_month_cents' => (int) $this->scoped($user, $card)
                ->paid()
                ->whereBetween('due_date', [
                    CarbonImmutable::now()->startOfMonth()->toDateString(),
                    CarbonImmutable::now()->endOfMonth()->toDateString(),
                ])
                ->sum('amount_cents'),
        ];
    }

    /** @return Builder<Installment> */
    private function open(User $user, ?Card $card): Builder
    {
        return $this->scoped($user, $card)->open();
    }

    /** @return Builder<Installment> */
    private function scoped(User $user, ?Card $card): Builder
    {
        $query = Installment::query()->forUser($user);

        if ($card !== null) {
            $query->forCard($card);
        }

        return $query;
    }
}
