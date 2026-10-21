<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildCardProjection
{
    private const OTHERS_KEY = 'outros';

    /**
     * Parcelas em aberto por mes, separadas por cartao. Os cartoes com mais
     * parcelas na janela ganham serie propria; o resto soma em "Outros", para
     * o empilhado nao passar de cinco cores.
     *
     * @return array{
     *     series: array<int, array{key: string, label: string}>,
     *     months: array<int, array{month: string, totals: array<string, int>}>,
     * }
     */
    public function handle(User $user, int $months = 6, int $top = 4): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $end = $start->addMonths($months)->subDay();

        $installments = Installment::query()
            ->forUser($user)
            ->open()
            ->whereDate('due_date', '<=', $end->toDateString())
            ->with('purchase:id,card_id', 'purchase.card:id,name')
            ->get(['id', 'purchase_id', 'due_date', 'amount_cents']);

        $ranked = $installments
            ->groupBy('purchase.card_id')
            ->map(fn (Collection $group): int => (int) $group->sum('amount_cents'))
            ->sortDesc();

        $featuredIds = $ranked->keys()->take($top)->all();
        $hasOthers = $ranked->count() > $top;

        $series = array_map(fn (int $cardId): array => [
            'key' => "card-{$cardId}",
            'label' => $installments->firstWhere('purchase.card_id', $cardId)->purchase->card->name,
        ], $featuredIds);

        if ($hasOthers) {
            $series[] = ['key' => self::OTHERS_KEY, 'label' => 'Outros'];
        }

        return [
            'series' => $series,
            'months' => array_map(function (int $offset) use ($start, $installments, $series, $featuredIds): array {
                $month = $start->addMonths($offset)->format('Y-m');
                $totals = array_fill_keys(array_column($series, 'key'), 0);

                foreach ($installments as $installment) {
                    if ($installment->due_date->format('Y-m') !== $month) {
                        continue;
                    }

                    $cardId = $installment->purchase->card_id;
                    $key = in_array($cardId, $featuredIds, true) ? "card-{$cardId}" : self::OTHERS_KEY;
                    $totals[$key] += $installment->amount_cents;
                }

                return ['month' => $month, 'totals' => $totals];
            }, range(0, $months - 1)),
        ];
    }
}
