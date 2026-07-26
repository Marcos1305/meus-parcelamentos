<?php

use App\Enums\InstallmentStatus;
use Carbon\CarbonImmutable;

it('classifica a parcela pela data de vencimento', function (string $dueDate, string $today, InstallmentStatus $expected): void {
    $status = InstallmentStatus::forDueDate(
        CarbonImmutable::parse($dueDate),
        CarbonImmutable::parse($today),
    );

    expect($status)->toBe($expected);
})->with([
    'mes passado' => ['2026-06-10', '2026-07-15', InstallmentStatus::Paid],
    'este mes, ja venceu' => ['2026-07-10', '2026-07-15', InstallmentStatus::Paid],
    'este mes, vence hoje' => ['2026-07-15', '2026-07-15', InstallmentStatus::Upcoming],
    'este mes, ainda vai vencer' => ['2026-07-28', '2026-07-15', InstallmentStatus::Upcoming],
    'mes que vem' => ['2026-08-10', '2026-07-15', InstallmentStatus::Pending],
    'ano que vem' => ['2027-01-10', '2026-07-15', InstallmentStatus::Pending],
    'mesmo mes de outro ano nao conta como atual' => ['2027-07-10', '2026-07-15', InstallmentStatus::Pending],
]);

it('considera em aberto tudo que nao esta pago', function (): void {
    expect(InstallmentStatus::Upcoming->isOpen())->toBeTrue()
        ->and(InstallmentStatus::Pending->isOpen())->toBeTrue()
        ->and(InstallmentStatus::Paid->isOpen())->toBeFalse();
});
