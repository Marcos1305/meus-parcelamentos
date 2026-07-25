<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use Carbon\CarbonImmutable;

function schedule(): BuildInstallmentSchedule
{
    return new BuildInstallmentSchedule(
        new SplitIntoInstallments,
        new CalculateFirstDueDate,
    );
}

it('numera as parcelas a partir de um', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect(array_column($result, 'number'))->toBe([1, 2, 3]);
});

it('distribui os valores com o resto na primeira', function (): void {
    $result = schedule()->handle(100000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect(array_column($result, 'amount_cents'))->toBe([33334, 33333, 33333]);
});

it('avanca um mes por parcela', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('nao propaga o corte de dia dos meses curtos', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2025-12-15'), 10, 31);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('atravessa a virada de ano', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-11-02'), 3, 10);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-11-10', '2026-12-10', '2027-01-10']);
});

it('gera uma unica linha para compra a vista', function (): void {
    $result = schedule()->handle(50000, 1, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect($result)->toHaveCount(1)
        ->and($result[0]['amount_cents'])->toBe(50000);
});
