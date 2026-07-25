<?php

use App\Actions\CalculateFirstDueDate;
use Carbon\CarbonImmutable;

it('calcula o primeiro vencimento', function (string $purchasedAt, int $closingDay, int $dueDay, string $expected): void {
    $result = (new CalculateFirstDueDate)->handle(
        CarbonImmutable::parse($purchasedAt),
        $closingDay,
        $dueDay,
    );

    expect($result->toDateString())->toBe($expected);
})->with([
    'antes do fechamento, vencimento depois do fechamento' => ['2026-03-02', 3, 10, '2026-03-10'],
    'depois do fechamento empurra um mes' => ['2026-03-05', 3, 10, '2026-04-10'],
    'vencimento antes do fechamento empurra um mes' => ['2026-03-02', 28, 5, '2026-04-05'],
    'dia 31 cortado em fevereiro' => ['2026-01-15', 10, 31, '2026-02-28'],
    'dia 31 cortado em fevereiro bissexto' => ['2024-01-15', 10, 31, '2024-02-29'],
    'virada de ano' => ['2026-12-20', 10, 15, '2027-01-15'],
    'compra no proprio dia do fechamento entra na fatura do mes' => ['2026-03-03', 3, 10, '2026-03-10'],
    'fechamento e vencimento no mesmo dia empurra um mes' => ['2026-03-01', 10, 10, '2026-04-10'],
]);
