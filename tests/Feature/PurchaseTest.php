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
