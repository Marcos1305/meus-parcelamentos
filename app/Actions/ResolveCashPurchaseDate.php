<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class ResolveCashPurchaseDate
{
    /**
     * A fatura traz dia e mes, nunca o ano. O ano e o do vencimento, exceto
     * quando isso colocaria a compra depois do proprio vencimento: uma linha
     * de 28/12 numa fatura que vence em janeiro e do ano anterior.
     */
    public function handle(int $day, int $month, CarbonImmutable $invoiceDueDate): CarbonImmutable
    {
        $candidate = $this->build($invoiceDueDate->year, $month, $day);

        return $candidate->greaterThan($invoiceDueDate)
            ? $this->build($invoiceDueDate->year - 1, $month, $day)
            : $candidate;
    }

    /**
     * O dia e encaixado no mes em vez de transbordar: somar 31 dias a um mes
     * de 28 daria marco, e a compra mudaria de mes sozinha.
     */
    private function build(int $year, int $month, int $day): CarbonImmutable
    {
        $firstDay = CarbonImmutable::create($year, $month, 1);

        return $firstDay->setDay(min($day, $firstDay->daysInMonth));
    }
}
