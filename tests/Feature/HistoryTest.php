<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function historyUser(): User
{
    return User::factory()->create();
}

function installmentFor(User $user, string $dueDate, int $amountCents, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()->for($card ?? Card::factory()->for($user))->create();

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('filtra pelo mes corrente por padrao', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor($user, '2026-08-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('history/index')
            ->has('installments.data', 1)
            ->where('filters.month', '2026-07'));
});

it('mostra todos os meses quando pedido', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor($user, '2026-08-10', 100_00);
    installmentFor($user, '2025-01-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 3));
});

it('filtra por status', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-06-10', 100_00);
    installmentFor($user, '2026-07-20', 100_00);
    installmentFor($user, '2026-09-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'paid']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1)
            ->where('installments.data.0.status', 'paid'));

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'pending']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('filtra por cartao', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();
    installmentFor($user, '2026-07-10', 100_00, $card);
    installmentFor($user, '2026-07-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id]))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('pagina em vinte e preserva os filtros', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    for ($i = 1; $i <= 25; $i++) {
        installmentFor($user, '2026-07-10', 100_00, $card);
    }

    $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 5)
            ->where('installments.total', 25));
});

it('ordena por vencimento ascendente', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-28', 100_00);
    installmentFor($user, '2026-07-02', 100_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.due_date', '2026-07-02'));
});

it('nao vaza parcelas de outro usuario', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor(historyUser(), '2026-07-10', 999_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('mantem os totais do topo globais, sem responder ao filtro', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-20', 100_00);
    installmentFor($user, '2026-11-10', 700_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => '2026-07']))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 1)
            ->where('summary.total_due_cents', 800_00));
});

it('exige autenticacao para o historico', function (): void {
    $this->get(route('history.index'))->assertRedirect(route('login'));
});
