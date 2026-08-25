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

it('entrega a observacao da compra na linha do historico', function (): void {
    $user = historyUser();
    $installment = installmentFor($user, '2026-07-10', 100_00);
    $installment->purchase->update(['notes' => 'Garantia até 2028.']);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.purchase.notes', 'Garantia até 2028.'));
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

it('busca por descricao, estabelecimento e observacao', function (string $termo): void {
    $user = historyUser();
    $alvo = installmentFor($user, '2026-07-10', 100_00);
    $alvo->purchase->update([
        'description' => 'Sofá de canto',
        'merchant' => 'Tok Stok',
        'notes' => 'Dividido com o João',
    ]);

    $outro = installmentFor($user, '2026-07-11', 100_00);
    $outro->purchase->update([
        'description' => 'Tênis',
        'merchant' => 'Centauro',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => $termo]))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 1)
            ->where('installments.data.0.purchase.description', 'Sofá de canto')
            ->where('filters.q', $termo));
})->with(['Sofá', 'Tok', 'João']);

it('ignora maiusculas na busca', function (): void {
    $user = historyUser();
    $alvo = installmentFor($user, '2026-07-10', 100_00);
    $alvo->purchase->update(['description' => 'Sofa de canto', 'merchant' => null, 'notes' => null]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'SOFA']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('combina a busca com os filtros de mes e cartao', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    $julho = installmentFor($user, '2026-07-10', 100_00, $card);
    $julho->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $agosto = installmentFor($user, '2026-08-10', 100_00, $card);
    $agosto->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $outroCartao = installmentFor($user, '2026-07-10', 100_00);
    $outroCartao->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'Sofá', 'month' => '2026-07', 'card' => $card->id]))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('devolve lista vazia quando a busca nao casa nada', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'zzzzzz']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('installments.data', 0));
});
