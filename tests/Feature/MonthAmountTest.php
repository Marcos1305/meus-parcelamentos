<?php

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use Illuminate\Database\QueryException;

it('recusa dois valores para a mesma linha no mesmo mes', function (): void {
    $line = BudgetLine::factory()->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-03-01']);

    expect(fn () => BudgetLineAmount::factory()->for($line)->create(['month' => '2026-03-01']))
        ->toThrow(QueryException::class);
});

it('recusa duas correcoes para o mesmo cartao no mesmo mes', function (): void {
    $card = Card::factory()->create();
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-03-01']);

    expect(fn () => CardMonthAmount::factory()->for($card)->create(['month' => '2026-03-01']))
        ->toThrow(QueryException::class);
});

it('devolve o mes como data imutavel', function (): void {
    $amount = BudgetLineAmount::factory()->create(['month' => '2026-03-01']);

    expect($amount->fresh()->month->format('Y-m'))->toBe('2026-03');
});

it('apaga os valores junto com a linha', function (): void {
    $line = BudgetLine::factory()->create();
    BudgetLineAmount::factory()->for($line)->create();

    $line->delete();

    expect(BudgetLineAmount::query()->count())->toBe(0);
});
