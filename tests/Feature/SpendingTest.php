<?php

use App\Enums\ExpenseCategory;
use App\Jobs\CategorizeExpensesJob;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('mostra a tela de gastos', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('spending.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('spending/index')
            ->has('breakdown.categories')
            ->has('breakdown.months', 12)
            ->has('categories', 12));
});

it('exige login', function (): void {
    $this->get(route('spending.index'))->assertRedirect(route('login'));
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
