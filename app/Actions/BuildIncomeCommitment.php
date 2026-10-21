<?php

namespace App\Actions;

use App\Enums\BudgetLineKind;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;

class BuildIncomeCommitment
{
    public function __construct(private BuildAnnualPlan $buildAnnualPlan) {}

    /**
     * Quanto da entrada de cada mes ja esta tomado por fatura de cartao e por
     * despesa fixa, comecando no mes corrente. A fatura e a do planejamento:
     * o valor informado quando existe, senao a soma das parcelas do mes.
     *
     * Entrada zerada vira null: um mes sem entrada informada mostraria tudo
     * como comprometido, o que nao e verdade, so falta o dado.
     *
     * O cartao filtrado restringe apenas a camada de cartao; as despesas fixas
     * e a entrada nao pertencem a cartao nenhum.
     *
     * @return array<int, array{month: string, card_cents: int, fixed_expense_cents: int, income_cents: int|null}>
     */
    public function handle(User $user, ?Card $card = null, int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $monthKeys = array_map(
            fn (int $offset): string => $start->addMonths($offset)->format('Y-m'),
            range(0, $months - 1),
        );

        $plans = [];

        foreach (array_unique(array_map(fn (string $month): int => (int) substr($month, 0, 4), $monthKeys)) as $year) {
            $plans[$year] = $this->buildAnnualPlan->handle($user, $year);
        }

        return array_map(function (string $month) use ($plans, $card): array {
            $plan = $plans[(int) substr($month, 0, 4)];

            $cardCents = 0;

            foreach ($plan['cards'] as $planCard) {
                if ($card === null || $planCard['id'] === $card->id) {
                    $cardCents += $planCard['months'][$month]['effective_cents'];
                }
            }

            $fixedExpenseCents = 0;
            $incomeCents = 0;

            foreach ($plan['lines'] as $line) {
                if ($line['kind'] === BudgetLineKind::Income->value) {
                    $incomeCents += $line['months'][$month]['effective_cents'];
                } else {
                    $fixedExpenseCents += $line['months'][$month]['effective_cents'];
                }
            }

            return [
                'month' => $month,
                'card_cents' => $cardCents,
                'fixed_expense_cents' => $fixedExpenseCents,
                'income_cents' => $incomeCents > 0 ? $incomeCents : null,
            ];
        }, $monthKeys);
    }
}
