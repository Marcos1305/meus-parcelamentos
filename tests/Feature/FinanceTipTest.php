<?php

use App\Actions\BuildFinanceTip;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function lastInstallmentDueOn(User $user, string $dueDate, int $amountCents, int $count = 3): Purchase
{
    $purchase = Purchase::factory()
        ->for(Card::factory()->for($user))
        ->create(['installments_count' => $count]);

    Installment::factory()->for($purchase)->create([
        'number' => $count,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);

    return $purchase;
}

it('devolve null quando nenhum parcelamento termina este mes', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-09-10', 100_00);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('conta os parcelamentos que terminam no mes corrente', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-07-20', 200_00);
    lastInstallmentDueOn($user, '2026-07-28', 480_00);

    $tip = app(BuildFinanceTip::class)->handle($user);

    expect($tip['count'])->toBe(2)
        ->and($tip['freed_cents'])->toBe(680_00)
        ->and($tip['next_month'])->toBe('2026-08');
});

it('ignora parcelas do meio do parcelamento', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['installments_count' => 5]);

    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => '2026-07-20', 'amount_cents' => 100_00]);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('ignora parcelamentos de outro usuario', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn(User::factory()->create(), '2026-07-20', 500_00);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('atravessa a virada de ano no mes seguinte', function (): void {
    $this->travelTo('2026-12-15');
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-12-20', 100_00);

    expect(app(BuildFinanceTip::class)->handle($user)['next_month'])->toBe('2027-01');
});
