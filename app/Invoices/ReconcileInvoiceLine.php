<?php

namespace App\Invoices;

use App\Actions\BuildInstallmentSchedule;
use Carbon\CarbonImmutable;

class ReconcileInvoiceLine
{
    public function __construct(
        private BuildInstallmentSchedule $buildInstallmentSchedule,
    ) {}

    /**
     * Resolve a compra que originou uma linha de fatura e confere o resultado
     * reprocessando o cronograma pelo pipeline oficial.
     */
    public function handle(
        ParsedInvoiceLine $line,
        CarbonImmutable $invoiceDueDate,
        int $closingDay,
        int $dueDay,
    ): InvoiceCandidate {
        $purchasedAt = $this->resolvePurchaseDate($line, $invoiceDueDate, $closingDay, $dueDay);
        $totalCents = $line->amountCents * $line->installmentsCount;

        $schedule = $this->buildInstallmentSchedule->handle(
            $totalCents,
            $line->installmentsCount,
            $purchasedAt,
            $closingDay,
            $dueDay,
        );

        $reprocessed = $schedule[$line->installmentNumber - 1]['due_date'];

        return new InvoiceCandidate(
            description: $line->description,
            merchant: $line->merchant,
            totalAmountCents: $totalCents,
            installmentsCount: $line->installmentsCount,
            installmentNumber: $line->installmentNumber,
            installmentAmountCents: $line->amountCents,
            purchasedAt: $purchasedAt,
            status: $reprocessed->isSameMonth($invoiceDueDate, true)
                ? CandidateStatus::Matched
                : CandidateStatus::Divergent,
        );
    }

    /**
     * Inverte CalculateFirstDueDate: parte do mes de vencimento da fatura,
     * recua ate o mes da primeira parcela e dai ate o mes da compra.
     *
     * A conta e feita em meses e o dia so e reconstruido no fim: subtrair
     * meses de uma data ja truncada (28/02 vinda de due_day 31) perde
     * informacao.
     */
    private function resolvePurchaseDate(
        ParsedInvoiceLine $line,
        CarbonImmutable $invoiceDueDate,
        int $closingDay,
        int $dueDay,
    ): CarbonImmutable {
        $firstDueMonth = $invoiceDueDate
            ->startOfMonth()
            ->subMonths($line->installmentNumber - 1);

        $closingMonth = $dueDay > $closingDay
            ? $firstDueMonth
            : $firstDueMonth->subMonth();

        $purchasedMonth = $line->transactionDay > $closingDay
            ? $closingMonth->subMonth()
            : $closingMonth;

        return $purchasedMonth->addDays(
            min($line->transactionDay, $purchasedMonth->daysInMonth) - 1,
        );
    }
}
