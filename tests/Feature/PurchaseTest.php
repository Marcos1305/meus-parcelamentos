<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('redireciona para o cadastro de cartao quando nao ha nenhum', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('purchases.create'))
        ->assertRedirect(route('cards.create'));
});

it('mostra o formulario quando ha ao menos um cartao', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('purchases.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchases/create')->has('cards', 1));
});

it('cria a compra e gera as parcelas', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'MacBook Pro M2',
            'merchant' => 'Apple Store',
            'total_amount_cents' => 100_000,
            'installments_count' => 3,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    $purchase = Purchase::sole();

    expect($purchase->installments()->count())->toBe(3)
        ->and($purchase->installments()->pluck('amount_cents')->all())->toBe([33334, 33333, 33333])
        ->and($purchase->installments()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('aceita compra a vista como uma parcela', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Almoço',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Installment::count())->toBe(1);
});

it('recusa lancamento em cartao de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Card::factory()->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $foreign->id,
            'description' => 'Invasão',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors('card_id');

    expect(Purchase::count())->toBe(0);
});

it('recusa compra com data no futuro', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Viagem no tempo',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => now()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors('purchased_at');
});

it('recusa mais de 48 parcelas e valor zero', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Absurdo',
            'total_amount_cents' => 0,
            'installments_count' => 49,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors(['total_amount_cents', 'installments_count']);
});

it('apaga a compra e suas parcelas', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->parcelada(6)->for(Card::factory()->for($user))->create();

    $this->actingAs($user)
        ->delete(route('purchases.destroy', $purchase))
        ->assertRedirect();

    expect(Purchase::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('nega apagar compra de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Purchase::factory()->parcelada(2)->create();

    $this->actingAs($user)
        ->delete(route('purchases.destroy', $foreign))
        ->assertForbidden();

    expect(Purchase::count())->toBe(1);
});

it('grava a observacao no lancamento manual', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => 'Dividido com o João, ele paga metade em janeiro.',
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Purchase::sole()->notes)->toBe('Dividido com o João, ele paga metade em janeiro.');
});

it('grava null quando a observacao vem vazia ou so com espacos', function (string $notes): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => $notes,
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Purchase::sole()->notes)->toBeNull();
})->with(['', '   ', "\n\t "]);

it('recusa observacao acima do limite', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => str_repeat('a', Purchase::MAX_NOTES_LENGTH + 1),
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors('notes');

    expect(Purchase::count())->toBe(0);
});

it('atualiza a observacao de uma compra existente', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => null]);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => 'Garantia até 2028.'])
        ->assertRedirect();

    expect($purchase->refresh()->notes)->toBe('Garantia até 2028.');
});

it('limpa a observacao quando o texto vem so com espacos', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => 'Nota antiga']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => '   ']);

    expect($purchase->refresh()->notes)->toBeNull();
});

it('recusa observacao acima do limite na edicao', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => 'Nota antiga']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => str_repeat('a', Purchase::MAX_NOTES_LENGTH + 1)])
        ->assertSessionHasErrors('notes');

    expect($purchase->refresh()->notes)->toBe('Nota antiga');
});

it('exige o campo notes na edicao', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create();

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), [])
        ->assertSessionHasErrors('notes');
});

it('nao deixa editar a observacao de compra de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Purchase::factory()->create(['notes' => 'Nota alheia']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $foreign), ['notes' => 'Invasão'])
        ->assertForbidden();

    expect($foreign->refresh()->notes)->toBe('Nota alheia');
});
