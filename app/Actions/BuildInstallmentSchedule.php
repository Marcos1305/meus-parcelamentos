<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class BuildInstallmentSchedule
{
    public function __construct(
        private SplitIntoInstallments $splitIntoInstallments,
        private CalculateFirstDueDate $calculateFirstDueDate,
    ) {}

    /**
     * Monta o cronograma completo de parcelas de uma compra.
     *
     * @return array<int, array{number: int, amount_cents: int, due_date: CarbonImmutable}>
     */
    public function handle(
        int $totalCents,
        int $count,
        CarbonImmutable $purchasedAt,
        int $closingDay,
        int $dueDay,
    ): array {
        $amounts = $this->splitIntoInstallments->handle($totalCents, $count);
        $firstDueDate = $this->calculateFirstDueDate->handle($purchasedAt, $closingDay, $dueDay);

        $schedule = [];

        foreach ($amounts as $index => $amountCents) {
            $month = $firstDueDate->startOfMonth()->addMonths($index);

            $schedule[] = [
                'number' => $index + 1,
                'amount_cents' => $amountCents,
                'due_date' => $month->addDays(min($dueDay, $month->daysInMonth) - 1),
            ];
        }

        return $schedule;
    }
}
