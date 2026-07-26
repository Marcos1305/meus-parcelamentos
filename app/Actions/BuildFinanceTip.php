<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BuildFinanceTip
{
    /**
     * Parcelamentos cuja ultima parcela vence no mes corrente.
     *
     * @return array{count: int, freed_cents: int, next_month: string}|null
     */
    public function handle(User $user): ?array
    {
        $now = CarbonImmutable::now();

        $installments = Installment::query()
            ->forUser($user)
            ->whereBetween('due_date', [
                $now->startOfMonth()->toDateString(),
                $now->endOfMonth()->toDateString(),
            ])
            ->whereHas(
                'purchase',
                fn (Builder $purchases) => $purchases->whereColumn('purchases.installments_count', 'installments.number'),
            )
            ->get(['amount_cents']);

        if ($installments->isEmpty()) {
            return null;
        }

        return [
            'count' => $installments->count(),
            'freed_cents' => (int) $installments->sum('amount_cents'),
            'next_month' => $now->addMonthNoOverflow()->format('Y-m'),
        ];
    }
}
