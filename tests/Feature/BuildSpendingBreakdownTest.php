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

it('soma o valor sem categoria no payload raiz e na serie de meses', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 12_345,
        'billed_at' => '2026-07-10',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    $julho = collect($breakdown['months'])->firstWhere('month', '2026-07');

    expect($breakdown['uncategorized_total_cents'])->toBe(12_345)
        ->and($julho['totals']['sem-categoria'])->toBe(12_345);
});

it('mantem o total sem categoria de um mes que nao e o selecionado na serie de 12 meses', function (): void {
    // O caso perigoso do fix 1: um mes fora do selecionado, com gasto sem
    // categoria, precisa continuar aparecendo na serie do grafico empilhado
    // com o valor certo — senao aquele mes renderiza mais barato que os
    // vizinhos, indistinguivel de uma queda real de gasto.
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 8_000,
        'billed_at' => '2026-03-10',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    $marco = collect($breakdown['months'])->firstWhere('month', '2026-03');

    expect($marco['totals']['sem-categoria'] ?? null)->toBe(8_000);
});

it('inclui o balde sem categoria na lista de categorias do mes, com rotulo e cor proprios', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 5_000,
        'billed_at' => '2026-07-10',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    $semCategoria = collect($breakdown['categories'])->firstWhere('category', 'sem-categoria');

    expect($semCategoria)->not->toBeNull()
        ->and($semCategoria['total_cents'])->toBe(5_000)
        ->and($semCategoria['label'])->toBe('Sem categoria');
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

it('conta a compra a vista de dezembro no mes da fatura de janeiro', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'purchased_at' => '2025-12-28',
        'billed_at' => '2026-01-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $janeiro = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-01-01'));
    $dezembro = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2025-12-01'));

    expect(collect($janeiro['categories'])->sum('total_cents'))->toBe(10_000)
        ->and(collect($dezembro['categories'])->sum('total_cents'))->toBe(0);
});

it('filtra os quatro blocos por uma categoria', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 5_000,
        'billed_at' => '2026-07-15',
        'category' => ExpenseCategory::Casa,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: ['mercado'],
    );

    // Sem o filtro, o total do mes seria 15_000 (mercado + casa) e a lista
    // teria duas entradas: se qualquer um desses numeros aparecer, o filtro
    // nao foi aplicado.
    $julho = collect($breakdown['months'])->firstWhere('month', '2026-07');

    expect(collect($breakdown['categories'])->pluck('category')->all())->toBe(['mercado'])
        ->and(collect($breakdown['categories'])->sum('total_cents'))->toBe(10_000)
        ->and($julho['totals'])->toBe(['mercado' => 10_000])
        ->and($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['category'])->toBe('mercado')
        ->and($breakdown['uncategorized_count'])->toBe(0)
        ->and($breakdown['uncategorized_total_cents'])->toBe(0)
        ->and($breakdown['active_categories'])->toBe(['mercado']);
});

it('filtra por duas categorias somando as duas, nao uma nem todas', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 5_000,
        'billed_at' => '2026-07-15',
        'category' => ExpenseCategory::Casa,
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 2_000,
        'billed_at' => '2026-07-20',
        'category' => ExpenseCategory::Outros,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: ['mercado', 'casa'],
    );

    expect(collect($breakdown['categories'])->pluck('category')->sort()->values()->all())->toBe(['casa', 'mercado'])
        ->and(collect($breakdown['categories'])->sum('total_cents'))->toBe(15_000)
        ->and($breakdown['entries'])->toHaveCount(2);
});

it('filtra por sem-categoria isolando so os gastos sem categoria', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 3_000,
        'billed_at' => '2026-07-15',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: [BuildSpendingBreakdown::UNCATEGORIZED_CATEGORY],
    );

    expect(collect($breakdown['categories'])->pluck('category')->all())->toBe(['sem-categoria'])
        ->and(collect($breakdown['categories'])->sum('total_cents'))->toBe(3_000)
        ->and($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['category'])->toBeNull()
        ->and($breakdown['uncategorized_count'])->toBe(1)
        ->and($breakdown['uncategorized_total_cents'])->toBe(3_000);
});

it('mantem no chip a categoria filtrada mesmo quando ela zera no mes', function (): void {
    $user = User::factory()->create();

    // Mercado so tem gasto em junho; em julho o filtro por mercado precisa
    // continuar oferecendo o chip "Mercado" (senao o usuario nao consegue
    // desmarca-lo), mas casa - que tem gasto em julho e nao esta filtrada -
    // continua na lista normalmente.
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'billed_at' => '2026-06-10',
        'category' => ExpenseCategory::Mercado,
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 4_000,
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Casa,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: ['mercado'],
    );

    $available = collect($breakdown['available_categories'])->keyBy('category');

    expect($available->has('mercado'))->toBeTrue()
        ->and($available->get('mercado')['total_cents'])->toBe(0)
        ->and($available->has('casa'))->toBeTrue()
        ->and($available->get('casa')['total_cents'])->toBe(4_000)
        // Os quatro blocos, porem, continuam vazios: mercado nao teve gasto
        // em julho, entao o filtro isola um mes sem resultado.
        ->and($breakdown['categories'])->toBe([])
        ->and($breakdown['entries'])->toBe([]);
});

it('aponta a entrada da parcela para a compra, nao para a parcela', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 1, 'due_day' => 10]);

    /**
     * Uma compra anterior desloca os ids. Sem ela, a compra e a primeira
     * parcela teriam ambas id 1, e a assercao passaria mesmo se o codigo
     * devolvesse o id errado.
     */
    Purchase::factory()->for($card)->parcelada(3)->create(['purchased_at' => '2026-01-15']);

    $purchase = Purchase::factory()->for($card)->parcelada(10)->create([
        'total_amount_cents' => 400_000,
        'purchased_at' => '2026-06-15',
        'category' => ExpenseCategory::Casa,
    ]);

    $installment = $purchase->installments()->first();

    expect($installment->id)->not->toBe($purchase->id);

    $breakdown = new BuildSpendingBreakdown()->handle($user, $installment->due_date->startOfMonth());

    $entry = collect($breakdown['entries'])->firstWhere('kind', 'purchase');

    expect($entry['id'])->toBe($purchase->id)
        ->and($entry['amount_cents'])->toBe(40_000)
        ->and($entry['installment_label'])->toBe('1/10')
        ->and($entry['category'])->toBe('casa')
        ->and($entry['card_name'])->toBe($card->name);
});

it('a busca estreita as entries sem mexer nos graficos', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'amount_cents' => 30_00,
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Posto Ipiranga',
        'amount_cents' => 200_00,
        'category' => ExpenseCategory::Transporte,
        'billed_at' => '2026-07-12',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        search: 'padaria',
    );

    expect($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['description'])->toBe('Padaria do Zé')
        ->and($breakdown['categories'])->toHaveCount(2)
        ->and(array_sum(array_column($breakdown['categories'], 'total_cents')))->toBe(230_00)
        ->and($breakdown['search'])->toBe('padaria');
});

it('a busca convive com o filtro de categoria', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria da esquina',
        'category' => ExpenseCategory::Transporte,
        'billed_at' => '2026-07-11',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: ['mercado'],
        search: 'padaria',
    );

    expect($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['description'])->toBe('Padaria do Zé');
});

it('a busca ignora maiusculas e casa parte da descricao', function (string $termo): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'billed_at' => '2026-07-10',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        search: $termo,
    );

    expect($breakdown['entries'])->toHaveCount(1);
})->with(['PADARIA', 'padaria', 'daria do']);

function gastosDeJulho(User $user): void
{
    $card = Card::factory()->for($user)->create(['name' => 'Zeta']);
    $outro = Card::factory()->for($user)->create(['name' => 'Alfa']);

    CashPurchase::factory()->for($card)->create([
        'description' => 'Zebra',
        'amount_cents' => 10_00,
        'purchased_at' => '2026-07-20',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Transporte,
    ]);

    CashPurchase::factory()->for($outro)->create([
        'description' => 'Abacate',
        'amount_cents' => 500_00,
        'purchased_at' => '2026-07-02',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);
}

it('ordena as entries de gastos pela coluna pedida', function (string $ordem, string $direcao, string $primeiro): void {
    $user = User::factory()->create();
    gastosDeJulho($user);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        sort: $ordem,
        direction: $direcao,
    );

    expect($breakdown['entries'][0]['description'])->toBe($primeiro);
})->with([
    ['descricao', 'asc', 'Abacate'],
    ['descricao', 'desc', 'Zebra'],
    ['cartao', 'asc', 'Abacate'],
    ['cartao', 'desc', 'Zebra'],
    ['data', 'desc', 'Zebra'],
    ['data', 'asc', 'Abacate'],
    ['valor', 'desc', 'Abacate'],
    ['valor', 'asc', 'Zebra'],
]);

it('joga o gasto sem categoria para o fim ao ordenar por categoria', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    CashPurchase::factory()->for($card)->create([
        'description' => 'Sem rótulo',
        'category' => null,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Com rótulo',
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        sort: 'categoria',
        direction: 'asc',
    );

    expect($breakdown['entries'][1]['description'])->toBe('Sem rótulo');
});
