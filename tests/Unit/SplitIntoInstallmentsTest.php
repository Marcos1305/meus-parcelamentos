<?php

use App\Actions\SplitIntoInstallments;

it('devolve o total intacto em uma parcela', function (): void {
    expect((new SplitIntoInstallments)->handle(10000, 1))->toBe([10000]);
});

it('coloca o resto na primeira parcela', function (): void {
    expect((new SplitIntoInstallments)->handle(100000, 3))->toBe([33334, 33333, 33333]);
});

it('divide exatamente quando nao ha resto', function (): void {
    expect((new SplitIntoInstallments)->handle(120000, 4))->toBe([30000, 30000, 30000, 30000]);
});

it('lida com valores degenerados sem perder centavos', function (): void {
    expect((new SplitIntoInstallments)->handle(1, 3))->toBe([1, 0, 0]);
});

it('sempre soma exatamente o total', function (int $total, int $count): void {
    $parcels = (new SplitIntoInstallments)->handle($total, $count);

    expect(array_sum($parcels))->toBe($total)
        ->and($parcels)->toHaveCount($count);
})->with([
    [100000, 3], [99999, 7], [1, 48], [7, 2], [123456789, 13], [50000, 1], [333, 48],
]);
