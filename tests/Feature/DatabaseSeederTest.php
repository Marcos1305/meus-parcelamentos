<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('cria um usuario demo com cartoes e parcelamentos', function (): void {
    $this->seed();

    $user = User::where('email', 'demo@exemplo.test')->sole();

    expect($user->cards()->count())->toBe(3)
        ->and(Purchase::count())->toBeGreaterThan(0)
        ->and(Installment::count())->toBeGreaterThan(0);
});

it('gera parcelas coerentes com o total de cada compra', function (): void {
    $this->seed();

    Purchase::with('installments')->get()->each(function (Purchase $purchase): void {
        expect($purchase->installments)->toHaveCount($purchase->installments_count)
            ->and((int) $purchase->installments->sum('amount_cents'))->toBe($purchase->total_amount_cents);
    });
});

it('e idempotente ao rodar duas vezes', function (): void {
    $this->seed();
    $this->seed();

    expect(User::where('email', 'demo@exemplo.test')->count())->toBe(1)
        ->and(Card::count())->toBe(3);
});
