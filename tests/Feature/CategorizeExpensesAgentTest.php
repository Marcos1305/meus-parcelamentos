<?php

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;

it('devolve a categoria de cada item', function (): void {
    CategorizeExpenses::fake([
        ['items' => [
            ['id' => 1, 'category' => 'mercado'],
            ['id' => 2, 'category' => 'combustivel'],
        ]],
    ]);

    $response = (new CategorizeExpenses)->prompt("1. Supermercado Extra\n2. Posto Ipiranga");

    expect($response['items'])->toHaveCount(2)
        ->and($response['items'][0]['category'])->toBe('mercado');
});

it('leva a taxonomia inteira nas instrucoes', function (): void {
    $instructions = (string) (new CategorizeExpenses)->instructions();

    foreach (ExpenseCategory::cases() as $category) {
        expect($instructions)->toContain($category->value)
            ->and($instructions)->toContain($category->hint());
    }
});

it('desliga o modo thinking no ollama', function (): void {
    expect((new CategorizeExpenses)->providerOptions('ollama'))->toBe(['think' => false])
        ->and((new CategorizeExpenses)->providerOptions('anthropic'))->toBe([]);
});
