<?php

use App\Enums\ExpenseCategory;

it('tem doze categorias', function (): void {
    expect(ExpenseCategory::cases())->toHaveCount(12);
});

it('expoe os valores como lista de string', function (): void {
    expect(ExpenseCategory::values())
        ->toBeArray()
        ->toContain('mercado', 'outros')
        ->toHaveCount(12);
});

it('da rotulo, dica e cor a cada categoria', function (): void {
    foreach (ExpenseCategory::cases() as $category) {
        expect($category->label())->not->toBeEmpty()
            ->and($category->hint())->not->toBeEmpty()
            ->and($category->color())->toMatch('/^#[0-9a-f]{6}$/');
    }
});

it('nao repete cor entre categorias', function (): void {
    $colors = array_map(
        fn (ExpenseCategory $category): string => $category->color(),
        ExpenseCategory::cases(),
    );

    expect(array_unique($colors))->toHaveCount(12);
});
