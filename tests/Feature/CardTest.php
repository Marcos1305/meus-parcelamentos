<?php

use App\Models\Card;
use App\Models\User;

it('lista apenas os cartoes do usuario autenticado', function (): void {
    $user = User::factory()->create();
    Card::factory()->count(2)->for($user)->create();
    Card::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('cards.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cards/index')
            ->has('cards', 2));
});

it('cria um cartao para o usuario autenticado', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'mastercard',
            'label' => 'Platinum',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 5_000_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertRedirect(route('cards.index'));

    expect($user->cards()->count())->toBe(1)
        ->and($user->cards()->first()->name)->toBe('Nubank');
});

it('rejeita dias fora da faixa de 1 a 31', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'visa',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 100_000,
            'closing_day' => 0,
            'due_day' => 32,
        ])
        ->assertSessionHasErrors(['closing_day', 'due_day']);
});

it('exige exatamente quatro digitos finais', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'visa',
            'last_four_digits' => '82',
            'credit_limit_cents' => 100_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertSessionHasErrors('last_four_digits');
});

it('rejeita bandeira desconhecida', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'diners',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 100_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertSessionHasErrors('brand');
});

it('atualiza um cartao proprio', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Antigo']);

    $this->actingAs($user)
        ->patch(route('cards.update', $card), [
            'name' => 'Novo',
            'brand' => $card->brand->value,
            'last_four_digits' => $card->last_four_digits,
            'credit_limit_cents' => $card->credit_limit_cents,
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
        ])
        ->assertRedirect(route('cards.index'));

    expect($card->fresh()->name)->toBe('Novo');
});

it('apaga um cartao proprio', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('cards.destroy', $card))
        ->assertRedirect(route('cards.index'));

    expect(Card::count())->toBe(0);
});

it('nega acesso a cartao de outro usuario', function (string $method, string $routeName): void {
    $user = User::factory()->create();
    $card = Card::factory()->create();

    $this->actingAs($user)
        ->call($method, route($routeName, $card))
        ->assertForbidden();
})->with([
    'edit' => ['get', 'cards.edit'],
    'update' => ['patch', 'cards.update'],
    'destroy' => ['delete', 'cards.destroy'],
]);

it('exige autenticacao', function (): void {
    $this->get(route('cards.index'))->assertRedirect(route('login'));
});
