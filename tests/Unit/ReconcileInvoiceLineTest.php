<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use App\Invoices\CandidateStatus;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\ReconcileInvoiceLine;
use Carbon\CarbonImmutable;

/**
 * tests/Unit nao sobe o container do Laravel (o Pest.php so estende
 * TestCase em Feature), entao a dependencia e montada a mao.
 */
function reconciler(): ReconcileInvoiceLine
{
    return new ReconcileInvoiceLine(
        new BuildInstallmentSchedule(new SplitIntoInstallments, new CalculateFirstDueDate),
    );
}

function invoiceLine(int $number, int $count, int $day, int $amountCents = 12_990): ParsedInvoiceLine
{
    return new ParsedInvoiceLine(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        transactionDay: $day,
        installmentNumber: $number,
        installmentsCount: $count,
        amountCents: $amountCents,
    );
}

it('resolve a data da compra a partir do vencimento da fatura', function (
    int $number,
    int $count,
    int $transactionDay,
    string $invoiceDueDate,
    int $closingDay,
    int $dueDay,
    string $expectedPurchasedAt,
): void {
    $candidate = reconciler()->handle(
        invoiceLine($number, $count, $transactionDay),
        CarbonImmutable::parse($invoiceDueDate),
        $closingDay,
        $dueDay,
    );

    expect($candidate->purchasedAt->toDateString())->toBe($expectedPurchasedAt)
        ->and($candidate->status)->toBe(CandidateStatus::Matched);
})->with([
    'primeira parcela, compra antes do fechamento' => [1, 10, 2, '2026-03-10', 3, 10, '2026-03-02'],
    'primeira parcela, compra depois do fechamento' => [1, 10, 5, '2026-04-10', 3, 10, '2026-03-05'],
    'terceira parcela recua dois meses' => [3, 10, 2, '2026-05-10', 3, 10, '2026-03-02'],
    'ultima parcela' => [10, 10, 2, '2026-12-10', 3, 10, '2026-03-02'],
    'vencimento antes do fechamento' => [1, 6, 2, '2026-04-05', 28, 5, '2026-03-02'],
    'fechamento e vencimento no mesmo dia' => [1, 3, 1, '2026-04-10', 10, 10, '2026-03-01'],
    'virada de ano' => [3, 12, 20, '2027-01-15', 10, 15, '2026-10-20'],
    'vencimento deslocado para o dia seguinte' => [2, 6, 2, '2026-05-11', 3, 10, '2026-04-02'],
]);

it('calcula o total como valor da parcela vezes a quantidade', function (): void {
    $candidate = reconciler()->handle(
        invoiceLine(3, 10, 2, 12_990),
        CarbonImmutable::parse('2026-05-10'),
        3,
        10,
    );

    expect($candidate->totalAmountCents)->toBe(129_900)
        ->and($candidate->installmentAmountCents)->toBe(12_990)
        ->and($candidate->installmentsCount)->toBe(10)
        ->and($candidate->installmentNumber)->toBe(3);
});

/**
 * Dia 31 cai em fevereiro depois da inversao e e truncado para 28, o que
 * antecipa o fechamento e joga a parcela para marco em vez de abril.
 */
it('marca como divergente quando o round-trip nao fecha', function (): void {
    $candidate = reconciler()->handle(
        invoiceLine(1, 3, 31),
        CarbonImmutable::parse('2026-04-10'),
        30,
        10,
    );

    expect($candidate->purchasedAt->toDateString())->toBe('2026-02-28')
        ->and($candidate->status)->toBe(CandidateStatus::Divergent);
});

it('resolver e reprocessar sempre devolve o mes de vencimento alvo', function (): void {
    $reconcile = reconciler();

    foreach ([1, 3, 10, 15, 28] as $closingDay) {
        foreach ([1, 5, 10, 20, 28] as $dueDay) {
            foreach ([1, 2, 6, 12] as $number) {
                foreach ([1, 15, 28] as $transactionDay) {
                    $candidate = $reconcile->handle(
                        invoiceLine($number, 12, $transactionDay),
                        CarbonImmutable::create(2026, 6, $dueDay),
                        $closingDay,
                        $dueDay,
                    );

                    expect($candidate->status)
                        ->toBe(CandidateStatus::Matched, "closing={$closingDay} due={$dueDay} n={$number} day={$transactionDay}");
                }
            }
        }
    }
});
