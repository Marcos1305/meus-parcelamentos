<?php

use App\Actions\BuildSpendingBreakdown;
use App\Enums\ExpenseCategory;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;

it('soma a compra a vista no mes em que a fatura cobrou', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'purchased_at' => '2026-06-28',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    $mercado = collect($breakdown['categories'])->firstWhere('category', 'mercado');

    expect($mercado['total_cents'])->toBe(10_000)
        ->and($breakdown['month'])->toBe('2026-07');
});

it('nao soma a compra a vista no mes da compra', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'purchased_at' => '2026-06-28',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-06-01'));

    expect(collect($breakdown['categories'])->sum('total_cents'))->toBe(0);
});

it('soma a parcela e nao a compra inteira', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 1, 'due_day' => 10]);

    $purchase = Purchase::factory()->for($card)->parcelada(10)->create([
        'total_amount_cents' => 400_000,
        'purchased_at' => '2026-06-15',
        'category' => ExpenseCategory::Casa,
    ]);

    $firstDueDate = $purchase->installments()->first()->due_date;

    $breakdown = new BuildSpendingBreakdown()->handle($user, $firstDueDate->startOfMonth());

    $casa = collect($breakdown['categories'])->firstWhere('category', 'casa');

    expect($casa['total_cents'])->toBe(40_000);
});

it('conta o que esta sem categoria', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'billed_at' => '2026-07-10',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect($breakdown['uncategorized_count'])->toBe(1);
});

it('nao mistura gasto de outro usuario', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->create([
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
        'amount_cents' => 99_999,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect(collect($breakdown['categories'])->sum('total_cents'))->toBe(0);
});

it('devolve a serie dos ultimos doze meses terminando no mes escolhido', function (): void {
    $user = User::factory()->create();

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect($breakdown['months'])->toHaveCount(12)
        ->and($breakdown['months'][0]['month'])->toBe('2025-08')
        ->and($breakdown['months'][11]['month'])->toBe('2026-07');
});
