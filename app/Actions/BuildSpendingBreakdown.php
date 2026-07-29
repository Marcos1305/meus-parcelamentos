<?php

namespace App\Actions;

use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSpendingBreakdown
{
    /**
     * Regime de caixa: o mes de um gasto e o mes em que a fatura o cobrou. Para
     * a compra a vista isso e o billed_at, gravado na importacao; para a
     * parcelada e o vencimento da parcela. Usar a data da compra para uma e o
     * vencimento para a outra erraria os dois meses na virada do ano.
     *
     * @return array{
     *   month: string,
     *   categories: array<int, array{category: string, label: string, color: string, total_cents: int}>,
     *   months: array<int, array{month: string, totals: array<string, int>}>,
     *   entries: array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>,
     *   uncategorized_count: int,
     * }
     */
    public function handle(User $user, CarbonImmutable $month, int $months = 12): array
    {
        $start = $month->startOfMonth();
        $seriesStart = $start->subMonths($months - 1);

        $cash = CashPurchase::query()
            ->forUser($user)
            ->with('card:id,name')
            ->whereDate('billed_at', '>=', $seriesStart->toDateString())
            ->whereDate('billed_at', '<=', $start->endOfMonth()->toDateString())
            ->get();

        $installments = Installment::query()
            ->forUser($user)
            ->with('purchase:id,card_id,description,installments_count,category,category_is_manual', 'purchase.card:id,name')
            ->whereDate('due_date', '>=', $seriesStart->toDateString())
            ->whereDate('due_date', '<=', $start->endOfMonth()->toDateString())
            ->get();

        return [
            'month' => $start->format('Y-m'),
            'categories' => $this->categories($cash, $installments, $start),
            'months' => $this->series($cash, $installments, $seriesStart, $months),
            'entries' => $this->entries($cash, $installments, $start),
            'uncategorized_count' => $this->uncategorizedCount($cash, $installments, $start),
        ];
    }

    /**
     * A lista traz so as categorias com gasto no mes: uma barra de zero nao
     * comunica nada e polui a legenda.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{category: string, label: string, color: string, total_cents: int}>
     */
    private function categories(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $totals = [];

        foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
            if ($category === null) {
                continue;
            }

            $totals[$category->value] = ($totals[$category->value] ?? 0) + $amountCents;
        }

        arsort($totals);

        return array_map(
            fn (string $value, int $totalCents): array => [
                'category' => $value,
                'label' => ExpenseCategory::from($value)->label(),
                'color' => ExpenseCategory::from($value)->color(),
                'total_cents' => $totalCents,
            ],
            array_keys($totals),
            $totals,
        );
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{month: string, totals: array<string, int>}>
     */
    private function series(Collection $cash, Collection $installments, CarbonImmutable $seriesStart, int $months): array
    {
        return array_map(
            function (int $offset) use ($cash, $installments, $seriesStart): array {
                $month = $seriesStart->addMonths($offset);
                $totals = [];

                foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
                    if ($category === null) {
                        continue;
                    }

                    $totals[$category->value] = ($totals[$category->value] ?? 0) + $amountCents;
                }

                return ['month' => $month->format('Y-m'), 'totals' => $totals];
            },
            range(0, $months - 1),
        );
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>
     */
    private function entries(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $entries = [];

        foreach ($cash as $purchase) {
            if (! $purchase->billed_at->isSameMonth($month, true)) {
                continue;
            }

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'cash',
                'description' => $purchase->description,
                'amount_cents' => $purchase->amount_cents,
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'installment_label' => null,
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        foreach ($installments as $installment) {
            if (! $installment->due_date->isSameMonth($month, true)) {
                continue;
            }

            $purchase = $installment->purchase;

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'purchase',
                'description' => $purchase->description,
                'amount_cents' => $installment->amount_cents,
                'purchased_at' => $installment->due_date->toDateString(),
                'installment_label' => "{$installment->number}/{$purchase->installments_count}",
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['amount_cents'] <=> $a['amount_cents']);

        return $entries;
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     */
    private function uncategorizedCount(Collection $cash, Collection $installments, CarbonImmutable $month): int
    {
        $count = 0;

        foreach ($this->inMonth($cash, $installments, $month) as [$category]) {
            if ($category === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Achata os dois tipos numa lista de (categoria, valor) do mes pedido, que
     * e a unica forma em que as somas os enxergam.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{0: ExpenseCategory|null, 1: int}>
     */
    private function inMonth(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $rows = [];

        foreach ($cash as $purchase) {
            if ($purchase->billed_at->isSameMonth($month, true)) {
                $rows[] = [$purchase->category, $purchase->amount_cents];
            }
        }

        foreach ($installments as $installment) {
            if ($installment->due_date->isSameMonth($month, true)) {
                $rows[] = [$installment->purchase->category, $installment->amount_cents];
            }
        }

        return $rows;
    }
}
