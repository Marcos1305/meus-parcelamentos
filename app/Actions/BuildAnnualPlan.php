<?php

namespace App\Actions;

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuildAnnualPlan
{
    /**
     * @return array{
     *     year: int,
     *     months: array<int, string>,
     *     cards: array<int, array{id: int, name: string, last_four_digits: string, color: string|null, months: array<string, array{computed_cents: int, override_cents: int|null, effective_cents: int}>}>,
     *     lines: array<int, array{id: int, kind: string, name: string, color: string|null, default_amount_cents: int, position: int, months: array<string, array{amount_cents: int|null, effective_cents: int}>}>,
     *     totals: array<string, array{income_cents: int, expense_cents: int, balance_cents: int}>,
     * }
     */
    public function handle(User $user, int $year): array
    {
        $months = $this->months($year);

        $cards = $this->cards($user, $year, $months);
        $lines = $this->lines($user, $year, $months);

        return [
            'year' => $year,
            'months' => $months,
            'cards' => $cards,
            'lines' => $lines,
            'totals' => $this->totals($months, $cards, $lines),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function months(int $year): array
    {
        $january = CarbonImmutable::create($year, 1, 1);

        return array_map(
            fn (int $offset): string => $january->addMonths($offset)->format('Y-m'),
            range(0, 11),
        );
    }

    /**
     * @param  array<int, string>  $months
     * @return array<int, array{id: int, name: string, last_four_digits: string, color: string|null, months: array<string, array{computed_cents: int, override_cents: int|null, effective_cents: int}>}>
     */
    private function cards(User $user, int $year, array $months): array
    {
        $cards = $user->cards()->orderBy('name')->orderBy('id')->get();
        $cardIds = $cards->pluck('id')->all();

        $computed = $this->computedByCard($cardIds, $year);
        $overrides = $this->overridesByCard($cardIds, $year);

        return $cards->map(function (Card $card) use ($months, $computed, $overrides): array {
            $byMonth = [];

            foreach ($months as $month) {
                $computedCents = $computed[$card->id][$month] ?? 0;
                $overrideCents = $overrides[$card->id][$month] ?? null;

                $byMonth[$month] = [
                    'computed_cents' => $computedCents,
                    'override_cents' => $overrideCents,
                    'effective_cents' => $overrideCents ?? $computedCents,
                ];
            }

            return [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
                'color' => $card->color?->value,
                'months' => $byMonth,
            ];
        })->all();
    }

    /**
     * A soma das parcelas que vencem em cada mes, vencidas ou nao. O filtro de
     * parcela em aberto do dashboard responde outra pergunta e aqui zeraria
     * todo mes ja passado.
     *
     * @param  array<int, int>  $cardIds
     * @return array<int, array<string, int>>
     */
    private function computedByCard(array $cardIds, int $year): array
    {
        if ($cardIds === []) {
            return [];
        }

        $rows = DB::table('installments')
            ->join('purchases', 'purchases.id', '=', 'installments.purchase_id')
            ->whereIn('purchases.card_id', $cardIds)
            ->whereDate('installments.due_date', '>=', "{$year}-01-01")
            ->whereDate('installments.due_date', '<=', "{$year}-12-31")
            ->get([
                'purchases.card_id as card_id',
                'installments.due_date as due_date',
                'installments.amount_cents as amount_cents',
            ]);

        $computed = [];

        foreach ($rows as $row) {
            $month = substr((string) $row->due_date, 0, 7);
            $cardId = (int) $row->card_id;

            $computed[$cardId][$month] = ($computed[$cardId][$month] ?? 0) + (int) $row->amount_cents;
        }

        return $computed;
    }

    /**
     * @param  array<int, int>  $cardIds
     * @return array<int, array<string, int>>
     */
    private function overridesByCard(array $cardIds, int $year): array
    {
        if ($cardIds === []) {
            return [];
        }

        $rows = DB::table('card_month_amounts')
            ->whereIn('card_id', $cardIds)
            ->whereDate('month', '>=', "{$year}-01-01")
            ->whereDate('month', '<=', "{$year}-12-31")
            ->get(['card_id', 'month', 'amount_cents']);

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[(int) $row->card_id][substr((string) $row->month, 0, 7)] = (int) $row->amount_cents;
        }

        return $overrides;
    }

    /**
     * @param  array<int, string>  $months
     * @return array<int, array{id: int, kind: string, name: string, color: string|null, default_amount_cents: int, position: int, months: array<string, array{amount_cents: int|null, effective_cents: int}>}>
     */
    private function lines(User $user, int $year, array $months): array
    {
        $lines = $user->budgetLines()->active()->orderBy('position')->orderBy('id')->get();

        $amounts = $this->amountsByLine($lines->pluck('id')->all(), $year);

        return $lines->map(function (BudgetLine $line) use ($months, $amounts): array {
            $byMonth = [];

            foreach ($months as $month) {
                $amountCents = $amounts[$line->id][$month] ?? null;

                $byMonth[$month] = [
                    'amount_cents' => $amountCents,
                    'effective_cents' => $amountCents ?? $line->default_amount_cents,
                ];
            }

            return [
                'id' => $line->id,
                'kind' => $line->kind->value,
                'name' => $line->name,
                'color' => $line->color?->value,
                'default_amount_cents' => $line->default_amount_cents,
                'position' => $line->position,
                'months' => $byMonth,
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $lineIds
     * @return array<int, array<string, int>>
     */
    private function amountsByLine(array $lineIds, int $year): array
    {
        if ($lineIds === []) {
            return [];
        }

        $rows = DB::table('budget_line_amounts')
            ->whereIn('budget_line_id', $lineIds)
            ->whereDate('month', '>=', "{$year}-01-01")
            ->whereDate('month', '<=', "{$year}-12-31")
            ->get(['budget_line_id', 'month', 'amount_cents']);

        $amounts = [];

        foreach ($rows as $row) {
            $amounts[(int) $row->budget_line_id][substr((string) $row->month, 0, 7)] = (int) $row->amount_cents;
        }

        return $amounts;
    }

    /**
     * @param  array<int, string>  $months
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, array{income_cents: int, expense_cents: int, balance_cents: int}>
     */
    private function totals(array $months, array $cards, array $lines): array
    {
        $totals = [];

        foreach ($months as $month) {
            $income = 0;
            $expense = 0;

            foreach ($lines as $line) {
                $effective = $line['months'][$month]['effective_cents'];

                if ($line['kind'] === BudgetLineKind::Income->value) {
                    $income += $effective;

                    continue;
                }

                $expense += $effective;
            }

            foreach ($cards as $card) {
                $expense += $card['months'][$month]['effective_cents'];
            }

            $totals[$month] = [
                'income_cents' => $income,
                'expense_cents' => $expense,
                'balance_cents' => $income - $expense,
            ];
        }

        return $totals;
    }
}
