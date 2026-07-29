<?php

use App\Actions\BuildSpendingBreakdown;
use App\Enums\ExpenseCategory;
use App\Jobs\CategorizeExpensesJob;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('exige login', function (): void {
    $this->get(route('spending.index'))->assertRedirect(route('login'));
});

it('mostra a tela de gastos', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user)
        ->get(route('spending.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('spending/index')
            ->has('breakdown.categories', 1)
            ->where('breakdown.categories.0.category', 'mercado')
            ->has('breakdown.months', 12)
            ->has('categories', 13));
});

it('cai no mes corrente quando o mes da url nao existe', function (string $mes): void {
    $this->actingAs(User::factory()->create())
        ->get(route('spending.index', ['mes' => $mes]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', CarbonImmutable::now()->format('Y-m')));
})->with(['2026-13', '2026-00', '9999-99', 'nao-e-mes', '2026-1', '']);

it('usa o mes informado na url quando ele e valido', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('spending.index', ['mes' => '2026-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', '2026-03'));
});

it('filtra a tela de gastos pela categoria informada na url', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Casa,
        'billed_at' => '2026-07-15',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => 'mercado']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('breakdown.categories', 1)
            ->where('breakdown.categories.0.category', 'mercado')
            ->where('breakdown.active_categories', ['mercado']));
});

it('ignora silenciosamente uma categoria inexistente na url', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => 'criptomoedas']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('breakdown.categories', 1)
            ->where('breakdown.active_categories', []));
});

it('mistura categoria valida e invalida na url mantendo so a valida', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Casa,
        'billed_at' => '2026-07-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => 'mercado,criptomoedas']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.active_categories', ['mercado'])
            ->has('breakdown.categories', 1)
            ->where('breakdown.categories.0.category', 'mercado'));
});

it('filtra pelo balde sem-categoria informado na url', function (): void {
    // Este e o round trip que o controller precisa acertar sozinho: a chave
    // sintetica 'sem-categoria' nao e um caso de ExpenseCategory, entao
    // precisa sobreviver a validacao por lista branca em categoryFilter()
    // sem cair no ramo "categoria inexistente, ignorar". O teste da Action
    // (BuildSpendingBreakdownTest) chama handle() direto com a constante
    // PHP e pula essa validacao — so um teste HTTP cobre o parsing da url.
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'amount_cents' => 10_000,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => null,
        'amount_cents' => 7_000,
        'billed_at' => '2026-07-15',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => 'sem-categoria']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.active_categories', [BuildSpendingBreakdown::UNCATEGORIZED_CATEGORY])
            ->has('breakdown.categories', 1)
            ->where('breakdown.categories.0.category', 'sem-categoria')
            ->where('breakdown.categories.0.total_cents', 7_000)
            ->where('breakdown.uncategorized_count', 1)
            ->where('breakdown.uncategorized_total_cents', 7_000));
});

it('degrada entrada hostil de categorias na url para o formato esperado', function (string|array $categorias, array $expectedActive): void {
    // Esta tela ja caiu com 500 por causa de ?mes=2026-13; o mesmo raciocinio
    // vale para ?categorias=, entao cada forma hostil de entrada precisa de
    // uma prova, nao so leitura de codigo.
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Casa,
        'billed_at' => '2026-07-15',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => $categorias]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.active_categories', $expectedActive));
})->with([
    'string vazia degrada para sem filtro' => ['', []],
    'valores repetidos nao duplicam o filtro' => ['mercado,mercado', ['mercado']],
    'sintaxe de array na url degrada para sem filtro' => [['mercado'], []],
    'espacos em branco ao redor do valor sao aparados' => [' mercado , casa ', ['mercado', 'casa']],
    'caixa diferente da chave real nao casa' => ['MERCADO', []],
    'nome do caso do enum, nao o valor, nao casa' => ['Mercado', []],
]);

it('aplica o filtro de categoria sem mes na url e o mes sem filtro na url', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => CarbonImmutable::now(),
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Casa,
        'billed_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['categorias' => 'mercado']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', CarbonImmutable::now()->format('Y-m'))
            ->where('breakdown.active_categories', ['mercado'])
            ->has('breakdown.categories', 1));

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.active_categories', []));
});

it('preserva o filtro de categoria ao trocar de mes', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-06-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Casa,
        'billed_at' => '2026-07-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-06', 'categorias' => 'mercado']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', '2026-06')
            ->where('breakdown.active_categories', ['mercado'])
            ->has('breakdown.categories', 1));

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'categorias' => 'mercado']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', '2026-07')
            ->where('breakdown.active_categories', ['mercado'])
            ->has('breakdown.categories', 1)
            ->where('breakdown.categories.0.category', 'mercado'));
});

it('troca a categoria de uma compra a vista e marca como manual', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['category' => ExpenseCategory::Outros]);

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $cash), ['category' => 'mercado'])
        ->assertRedirect();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado)
        ->and($cash->fresh()->category_is_manual)->toBeTrue();
});

it('recusa categoria fora da lista', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create();

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $cash), ['category' => 'criptomoedas'])
        ->assertSessionHasErrors('category');
});

it('nao deixa editar categoria de cartao alheio', function (): void {
    $user = User::factory()->create();
    $other = CashPurchase::factory()->create();

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $other), ['category' => 'mercado'])
        ->assertForbidden();
});

it('enfileira a recategorizacao', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('spending.categorize'))
        ->assertRedirect();

    Queue::assertPushed(CategorizeExpensesJob::class);
});

it('troca a categoria de uma compra parcelada', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $purchase = Purchase::factory()->for($card)->create(['category' => null]);

    $this->actingAs($user)
        ->patch(route('spending.purchase-category', $purchase), ['category' => 'casa'])
        ->assertRedirect();

    expect($purchase->fresh()->category)->toBe(ExpenseCategory::Casa)
        ->and($purchase->fresh()->category_is_manual)->toBeTrue();
});

it('nao deixa editar categoria de compra parcelada alheia', function (): void {
    $other = Purchase::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('spending.purchase-category', $other), ['category' => 'casa'])
        ->assertForbidden();
});

it('limpa a categoria e desmarca como manual quando category e null', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create([
        'category' => ExpenseCategory::Mercado,
        'category_is_manual' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $cash), ['category' => null])
        ->assertRedirect();

    expect($cash->fresh()->category)->toBeNull()
        ->and($cash->fresh()->category_is_manual)->toBeFalse();
});
