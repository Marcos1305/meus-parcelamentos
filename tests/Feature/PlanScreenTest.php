<?php

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

test('visitante e mandado para o login', function (): void {
    $this->get(route('plan.index'))->assertRedirect(route('login'));
});

it('entrega os doze meses do ano corrente por padrao', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('plan/index')
            ->where('plan.year', 2026)
            ->has('plan.months', 12));
});

it('aceita o ano na query string', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index', ['ano' => 2027]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.year', 2027));
});

it('cai no ano corrente quando o ano e invalido', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index', ['ano' => 'abacaxi']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.year', 2026));
});

it('oferece o ano anterior, o corrente e o seguinte', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('years', [2025, 2026, 2027]));
});

it('cria a linha de entrada na primeira visita', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('plan.index'))->assertOk();

    $lines = BudgetLine::query()->where('user_id', $user->id)->get();

    expect($lines)->toHaveCount(1)
        ->and($lines->first()->kind)->toBe(BudgetLineKind::Income)
        ->and($lines->first()->name)->toBe('Entrada')
        ->and($lines->first()->default_amount_cents)->toBe(0);
});

it('nao duplica a linha de entrada em visitas seguintes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('plan.index'));
    $this->actingAs($user)->get(route('plan.index', ['ano' => 2027]));

    expect(BudgetLine::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('reaproveita a linha de entrada que ja existe em vez de criar outra', function (): void {
    $user = User::factory()->create();
    $existente = BudgetLine::factory()->for($user)->income()->create(['name' => 'Salario']);

    $this->actingAs($user)->get(route('plan.index'))->assertOk();

    expect(BudgetLine::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($existente->fresh()->name)->toBe('Salario');
});
