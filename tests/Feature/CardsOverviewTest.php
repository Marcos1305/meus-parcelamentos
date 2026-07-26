<?php

use App\Actions\BuildCardsOverview;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

it('calcula comprometido, disponivel e proporcao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['credit_limit_cents' => 1_000_00]);
    $purchase = Purchase::factory()->for($card)->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 500_00]);
    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => '2026-08-10', 'amount_cents' => 250_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['committed_cents'])->toBe(250_00)
        ->and($overview[0]['available_cents'])->toBe(750_00)
        ->and($overview[0]['committed_ratio'])->toBe(0.25);
});

it('agrupa o proximo vencimento por data', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $a = Purchase::factory()->for($card)->create();
    $b = Purchase::factory()->for($card)->create();

    Installment::factory()->for($a)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 100_00]);
    Installment::factory()->for($b)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 200_00]);
    Installment::factory()->for($b)->create(['number' => 2, 'due_date' => '2026-09-10', 'amount_cents' => 900_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['next_due_date'])->toBe('2026-08-10')
        ->and($overview[0]['next_due_cents'])->toBe(300_00);
});

it('destaca o parcelamento com maior valor restante', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $small = Purchase::factory()->for($card)->create(['description' => 'Tênis', 'total_amount_cents' => 300_00]);
    Installment::factory()->for($small)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 300_00]);

    $big = Purchase::factory()->for($card)->create(['description' => 'MacBook', 'total_amount_cents' => 1_200_00]);
    Installment::factory()->for($big)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 600_00]);
    Installment::factory()->for($big)->create(['number' => 2, 'due_date' => '2026-08-10', 'amount_cents' => 600_00]);

    $featured = app(BuildCardsOverview::class)->handle($user)[0]['featured'];

    expect($featured['description'])->toBe('MacBook')
        ->and($featured['remaining_cents'])->toBe(600_00)
        ->and($featured['paid_cents'])->toBe(600_00)
        ->and($featured['current_number'])->toBe(2)
        ->and($featured['installments_count'])->toBe(2);
});

it('desempata pelo parcelamento mais recente', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $older = Purchase::factory()->for($card)->create(['description' => 'Antigo', 'purchased_at' => '2026-01-10']);
    Installment::factory()->for($older)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 500_00]);

    $newer = Purchase::factory()->for($card)->create(['description' => 'Recente', 'purchased_at' => '2026-05-10']);
    Installment::factory()->for($newer)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 500_00]);

    expect(app(BuildCardsOverview::class)->handle($user)[0]['featured']['description'])->toBe('Recente');
});

it('nao destaca nada quando tudo ja foi pago', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $purchase = Purchase::factory()->for($card)->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 500_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['featured'])->toBeNull()
        ->and($overview[0]['next_due_date'])->toBeNull()
        ->and($overview[0]['committed_cents'])->toBe(0);
});

it('devolve cartao sem compras com tudo zerado', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['credit_limit_cents' => 500_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['committed_cents'])->toBe(0)
        ->and($overview[0]['available_cents'])->toBe(500_00)
        ->and($overview[0]['committed_ratio'])->toBe(0.0)
        ->and($overview[0]['featured'])->toBeNull();
});

it('nao vaza cartoes de outro usuario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    Card::factory()->count(3)->create();

    expect(app(BuildCardsOverview::class)->handle($user))->toHaveCount(1);
});

it('entrega a visao enriquecida para a tela', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('cards.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cards/index')
            ->has('cards.0.committed_cents')
            ->has('cards.0.available_cents'));
});
