<?php

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
