<?php

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
