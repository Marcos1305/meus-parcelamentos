<?php

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;

it('guarda o tipo como enum', function (): void {
    $line = BudgetLine::factory()->income()->create();

    expect($line->fresh()->kind)->toBe(BudgetLineKind::Income);
});

it('o escopo active esconde linha arquivada', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Energia']);
    BudgetLine::factory()->for($user)->archived()->create(['name' => 'Netflix']);

    $names = $user->budgetLines()->active()->pluck('name')->all();

    expect($names)->toBe(['Energia']);
});

it('apaga as linhas junto com o usuario', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create();

    $user->delete();

    expect(BudgetLine::query()->count())->toBe(0);
});
