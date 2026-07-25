<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class CalculateFirstDueDate
{
    /**
     * Determina o vencimento da primeira parcela de uma compra.
     *
     * A compra entra na fatura que fecha no mes da compra quando o dia da
     * compra nao passou do fechamento; caso contrario, na fatura seguinte.
     * O vencimento cai no mes do fechamento quando o dia de vencimento e
     * posterior ao de fechamento, e no mes seguinte quando nao e.
     */
    public function handle(CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): CarbonImmutable
    {
        $closingMonth = $purchasedAt->startOfMonth();

        if ($purchasedAt->day > $closingDay) {
            $closingMonth = $closingMonth->addMonth();
        }

        $dueMonth = $dueDay > $closingDay
            ? $closingMonth
            : $closingMonth->addMonth();

        return $dueMonth->addDays(min($dueDay, $dueMonth->daysInMonth) - 1);
    }
}
