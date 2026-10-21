<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;

class BuildPayoffCurve
{
    /**
     * O saldo em aberto no inicio de cada mes, do corrente ate o primeiro mes
     * sem nada a pagar. Vazio quando nao ha parcela em aberto.
     *
     * @return array<int, array{month: string, remaining_cents: int}>
     */
    public function handle(User $user, ?Card $card = null): array
    {
        $query = Installment::query()->forUser($user)->open();

        if ($card !== null) {
            $query->forCard($card);
        }

        $byMonth = $query
            ->get(['due_date', 'amount_cents'])
            ->groupBy(fn (Installment $installment): string => $installment->due_date->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount_cents'));

        if ($byMonth->isEmpty()) {
            return [];
        }

        $month = CarbonImmutable::now()->startOfMonth();
        $lastMonth = $byMonth->keys()->max();
        $remaining = $byMonth->sum();
        $curve = [];

        while ($month->format('Y-m') <= $lastMonth) {
            $curve[] = ['month' => $month->format('Y-m'), 'remaining_cents' => $remaining];
            $remaining -= $byMonth->get($month->format('Y-m'), 0);
            $month = $month->addMonth();
        }

        $curve[] = ['month' => $month->format('Y-m'), 'remaining_cents' => 0];

        return $curve;
    }
}
