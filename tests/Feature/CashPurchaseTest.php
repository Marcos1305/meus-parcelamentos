<?php

use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('grava uma compra a vista', function (): void {
    $cash = CashPurchase::factory()->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
    ]);

    expect($cash->description)->toBe('Padaria do Bairro')
        ->and($cash->amount_cents)->toBe(1_990)
        ->and($cash->category)->toBeNull()
        ->and($cash->category_is_manual)->toBeFalse();
});

it('converte a categoria para o enum', function (): void {
    $cash = CashPurchase::factory()->create(['category' => ExpenseCategory::Mercado]);

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado);
});

it('recusa duas compras identicas no mesmo cartao e dia', function (): void {
    $first = CashPurchase::factory()->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => '2026-06-05',
    ]);

    $duplicate = [
        'card_id' => $first->card_id,
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => '2026-06-05',
        'billed_at' => '2026-07-10',
        'category_is_manual' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('cash_purchases')->insertOrIgnore([$duplicate]);

    expect(CashPurchase::query()->count())->toBe(1);
});

it('so enxerga as compras dos cartoes do proprio usuario', function (): void {
    $user = User::factory()->create();
    $mine = CashPurchase::factory()->forUser($user)->create();
    CashPurchase::factory()->create();

    expect(CashPurchase::query()->forUser($user)->pluck('id')->all())->toBe([$mine->id]);
});
