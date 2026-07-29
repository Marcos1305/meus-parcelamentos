<?php

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;
use App\Jobs\CategorizeExpensesJob;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('categoriza compras a vista sem categoria', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Supermercado Extra']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado)
        ->and($cash->fresh()->category_is_manual)->toBeFalse();
});

it('nao sobrescreve categoria definida pelo usuario', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create([
        'description' => 'Supermercado Extra',
        'category' => ExpenseCategory::Lazer,
        'category_is_manual' => true,
    ]);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Lazer);
});

it('nao atribui categoria a uma linha marcada manual mesmo sem categoria', function (): void {
    // Uma categoria manual apagada (o usuario decidiu que nao ha categoria e
    // nao quer que o job tente de novo) ainda passa pelo whereNull('category')
    // da consulta: quem barra a escrita aqui e o category_is_manual dentro do
    // laco, nao o filtro da consulta.
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create([
        'description' => 'Supermercado Extra',
        'category' => null,
        'category_is_manual' => true,
    ]);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBeNull();
});

it('categoriza compras parceladas tambem', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()
        ->for(Card::factory()->for($user))
        ->create(['description' => 'Posto Ipiranga']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'combustivel']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($purchase->fresh()->category)->toBe(ExpenseCategory::Combustivel);
});

it('ignora categoria que nao existe no enum', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Loja Qualquer']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'criptomoedas']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBeNull();
});

it('descarta id fora da faixa do lote em vez de escrever na linha errada', function (): void {
    // O lote tem 1 linha, entao so o id 1 e valido. Um id fora da faixa deve
    // ser descartado, e nao "arredondado" para a unica linha existente.
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Supermercado Extra']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 0, 'category' => 'mercado'], ['id' => 2, 'category' => 'combustivel']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBeNull();
});

it('nao toca em compras de outro usuario', function (): void {
    $user = User::factory()->create();
    $other = CashPurchase::factory()->create(['description' => 'Supermercado Extra']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($other->fresh()->category)->toBeNull();
});

it('categoriza os dois tipos na mesma passagem', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Supermercado Extra']);
    $purchase = Purchase::factory()->for($card)->create(['description' => 'Posto Ipiranga']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
        ['items' => [['id' => 1, 'category' => 'combustivel']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado)
        ->and($purchase->fresh()->category)->toBe(ExpenseCategory::Combustivel);
});

it('nao enfileira duas vezes para o mesmo usuario enquanto a primeira nao processa', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    CategorizeExpensesJob::dispatch($user->id);
    CategorizeExpensesJob::dispatch($user->id);

    Queue::assertPushed(CategorizeExpensesJob::class, 1);
});

it('define um prazo para o bloqueio de unicidade se curar sozinho', function (): void {
    // Zero (o padrao sem uniqueFor) e "nunca expira" para o Lock: um worker
    // morto antes de liberar o lock travaria a categorizacao do usuario para
    // sempre. Precisa ser maior que o timeout do job (600s).
    expect(new CategorizeExpensesJob(1)->uniqueFor())->toBeGreaterThan(600);
});
