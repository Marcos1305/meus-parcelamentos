<?php

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\User;

it('grava o valor de uma linha num mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 573_74])
        ->assertRedirect();

    expect(BudgetLineAmount::query()->where('budget_line_id', $line->id)->value('amount_cents'))
        ->toBe(573_74);
});

it('sobrescreve o valor ja gravado do mesmo mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01', 'amount_cents' => 100_00]);

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 200_00]);

    expect(BudgetLineAmount::query()->count())->toBe(1)
        ->and(BudgetLineAmount::query()->value('amount_cents'))->toBe(200_00);
});

it('amount_cents nulo apaga o valor do mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01']);

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => null]);

    expect(BudgetLineAmount::query()->count())->toBe(0);
});

it('recusa amount_cents ausente para nao apagar por acidente', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), [])
        ->assertSessionHasErrors('amount_cents');
});

it('recusa valor negativo', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => -1])
        ->assertSessionHasErrors('amount_cents');
});

it('recusa mes malformado', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-13']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('nao enxerga linha de outro usuario', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.lines.amount', [$alheia->id, '2026-05']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('recusa valor em linha arquivada', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->archived()->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('grava a correcao de um cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => 2_147_33]);

    expect(CardMonthAmount::query()->value('amount_cents'))->toBe(2_147_33);
});

it('amount_cents nulo apaga a correcao do cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01']);

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => null]);

    expect(CardMonthAmount::query()->count())->toBe(0);
});

it('aceita correcao de zero', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => 0]);

    expect(CardMonthAmount::query()->value('amount_cents'))->toBe(0);
});

it('nao enxerga cartao de outro usuario', function (): void {
    $alheio = Card::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.cards.amount', [$alheio->id, '2026-09']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('cria uma linha no fim da lista', function (): void {
    $user = User::factory()->create();
    // Nome fixo e diferente de 'Internet' para nao colidir com o nome
    // aleatorio da factory e confundir o firstOrFail() por nome abaixo.
    BudgetLine::factory()->for($user)->create(['position' => 4, 'name' => 'Aluguel']);

    $this->actingAs($user)->post(route('plan.lines.store'), [
        'kind' => 'expense',
        'name' => 'Internet',
        'default_amount_cents' => 99_90,
    ]);

    $line = BudgetLine::query()->where('name', 'Internet')->firstOrFail();

    expect($line->position)->toBe(5)
        ->and($line->user_id)->toBe($user->id);
});

it('recusa linha sem nome', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('plan.lines.store'), [
            'kind' => 'expense',
            'name' => '',
            'default_amount_cents' => 100,
        ])
        ->assertSessionHasErrors('name');
});

it('recusa tipo fora do enum', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('plan.lines.store'), [
            'kind' => 'investimento',
            'name' => 'Tesouro',
            'default_amount_cents' => 100,
        ])
        ->assertSessionHasErrors('kind');
});

it('renomeia a linha', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create(['name' => 'Luz']);

    $this->actingAs($user)->patch(route('plan.lines.update', $line->id), [
        'name' => 'Energia',
        'default_amount_cents' => 400_00,
        'position' => 1,
    ]);

    expect($line->fresh()->name)->toBe('Energia');
});

it('a seta para cima troca a linha com a vizinha', function (): void {
    $user = User::factory()->create();
    $primeira = BudgetLine::factory()->for($user)->create(['name' => 'Agua', 'position' => 0]);
    $segunda = BudgetLine::factory()->for($user)->create(['name' => 'Energia', 'position' => 1]);

    $this->actingAs($user)->patch(route('plan.lines.update', $segunda->id), [
        'name' => $segunda->name,
        'default_amount_cents' => $segunda->default_amount_cents,
        'position' => 0,
    ]);

    expect($segunda->fresh()->position)->toBe(0)
        ->and($primeira->fresh()->position)->toBe(1);
});

it('renumera as posicoes de zero sem buracos', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['position' => 5]);
    BudgetLine::factory()->for($user)->create(['position' => 9]);
    $ultima = BudgetLine::factory()->for($user)->create(['position' => 40]);

    $this->actingAs($user)->patch(route('plan.lines.update', $ultima->id), [
        'name' => $ultima->name,
        'default_amount_cents' => $ultima->default_amount_cents,
        'position' => 40,
    ]);

    expect($user->budgetLines()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

it('arquiva a linha em vez de apagar', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('plan.lines.destroy', $line->id));

    expect($line->fresh()->archived_at)->not->toBeNull()
        ->and(BudgetLine::query()->count())->toBe(1);
});

it('nao arquiva linha de outro usuario', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('plan.lines.destroy', $alheia->id))
        ->assertNotFound();
});
