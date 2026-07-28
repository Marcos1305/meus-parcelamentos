<?php

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\BudgetLinePayment;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\CardPayment;
use App\Models\HiddenPlanMonth;
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

    expect($line->position)->toBe(1)
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

it('a seta para cima troca com a despesa vizinha mesmo com renda intercalada', function (): void {
    $user = User::factory()->create();
    // Renda criada primeiro (id menor) com a mesma posicao da primeira
    // despesa: a sequencia de position nao e mais global, entao tipos
    // diferentes podem repetir numero sem colidir. Se o reposition ainda
    // olhasse todos os tipos, essa renda entraria na contagem do indice e
    // a despesa do fim pularia direto para o topo em vez de so trocar com
    // a vizinha imediata.
    BudgetLine::factory()->for($user)->income()->create(['name' => 'Salario', 'position' => 0]);
    $despesaA = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Agua', 'position' => 0]);
    $despesaB = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Aluguel', 'position' => 1]);
    $despesaC = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Internet', 'position' => 2]);

    $this->actingAs($user)->patch(route('plan.lines.update', $despesaC->id), [
        'name' => $despesaC->name,
        'default_amount_cents' => $despesaC->default_amount_cents,
        'position' => 1,
    ]);

    expect($despesaA->fresh()->position)->toBe(0)
        ->and($despesaC->fresh()->position)->toBe(1)
        ->and($despesaB->fresh()->position)->toBe(2);
});

it('linha arquivada no meio nao impede a troca entre duas ativas do mesmo tipo', function (): void {
    $user = User::factory()->create();
    // Arquivada criada primeiro (id menor) com a mesma posicao da primeira
    // ativa: se o reposition ainda contasse linhas arquivadas, ela entraria
    // no indice no lugar da vizinha ativa e a linha movida pularia direto
    // para o topo em vez de so trocar com quem esta logo acima.
    BudgetLine::factory()->for($user)->expense()->archived()->create(['name' => 'Academia', 'position' => 0]);
    $ativaA = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Agua', 'position' => 0]);
    $ativaB = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Aluguel', 'position' => 1]);
    $ativaC = BudgetLine::factory()->for($user)->expense()->create(['name' => 'Internet', 'position' => 2]);

    $this->actingAs($user)->patch(route('plan.lines.update', $ativaC->id), [
        'name' => $ativaC->name,
        'default_amount_cents' => $ativaC->default_amount_cents,
        'position' => 1,
    ]);

    expect($ativaA->fresh()->position)->toBe(0)
        ->and($ativaC->fresh()->position)->toBe(1)
        ->and($ativaB->fresh()->position)->toBe(2);
});

it('a linha de renda nao entra na contagem de posicao das despesas', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->income()->create(['position' => 3]);
    BudgetLine::factory()->for($user)->expense()->create(['position' => 7]);

    $this->actingAs($user)->post(route('plan.lines.store'), [
        'kind' => 'expense',
        'name' => 'Streaming',
        'default_amount_cents' => 30_00,
    ]);

    expect(BudgetLine::query()->where('name', 'Streaming')->value('position'))->toBe(1);
});

it('recusa criar uma segunda linha de renda', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('plan.lines.store'), [
            'kind' => 'income',
            'name' => 'Freelance',
            'default_amount_cents' => 50_00,
        ])
        ->assertSessionHasErrors('kind');

    expect(BudgetLine::query()->where('name', 'Freelance')->exists())->toBeFalse();
});

it('renomear uma linha criada pelo store nao reordena as demais do mesmo tipo', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('plan.lines.store'), ['kind' => 'expense', 'name' => 'Agua', 'default_amount_cents' => 100_00]);
    $this->post(route('plan.lines.store'), ['kind' => 'expense', 'name' => 'Luz', 'default_amount_cents' => 200_00]);
    $this->post(route('plan.lines.store'), ['kind' => 'expense', 'name' => 'Net', 'default_amount_cents' => 300_00]);

    $luz = BudgetLine::query()->where('name', 'Luz')->firstOrFail();

    $this->patch(route('plan.lines.update', $luz->id), [
        'name' => 'Energia',
        'default_amount_cents' => $luz->default_amount_cents,
        'position' => $luz->position,
    ]);

    expect($user->budgetLines()->orderBy('position')->pluck('name')->all())
        ->toBe(['Agua', 'Energia', 'Net']);
});

it('grava a cor de uma linha', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.color', $line->id), ['color' => '#3b82f6'])
        ->assertRedirect();

    expect($line->fresh()->color)->toBe('#3b82f6');
});

it('cor nula tira a cor da linha', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create(['color' => '#3b82f6']);

    $this->actingAs($user)->put(route('plan.lines.color', $line->id), ['color' => null]);

    expect($line->fresh()->color)->toBeNull();
});

it('recusa cor ausente para nao apagar por acidente', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create(['color' => '#3b82f6']);

    $this->actingAs($user)
        ->put(route('plan.lines.color', $line->id), [])
        ->assertSessionHasErrors('color');

    expect($line->fresh()->color)->toBe('#3b82f6');
});

it('recusa cor fora da paleta', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.color', $line->id), ['color' => 'nao-e-hex'])
        ->assertSessionHasErrors('color');
});

it('trocar a cor nao mexe na ordem das linhas', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['Agua', 'Luz', 'Net'] as $nome) {
        $this->post(route('plan.lines.store'), [
            'kind' => 'expense',
            'name' => $nome,
            'default_amount_cents' => 100_00,
        ]);
    }

    $meio = BudgetLine::query()->where('name', 'Luz')->firstOrFail();

    $this->put(route('plan.lines.color', $meio->id), ['color' => '#22c55e']);

    $ordem = BudgetLine::query()->where('user_id', $user->id)
        ->orderBy('position')->orderBy('id')->pluck('name')->all();

    expect($ordem)->toBe(['Agua', 'Luz', 'Net']);
});

it('grava a cor de um cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->put(route('plan.cards.color', $card->id), ['color' => '#8b5cf6']);

    expect($card->fresh()->color)->toBe('#8b5cf6');
});

it('nao pinta linha de outro usuario', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.lines.color', $alheia->id), ['color' => '#3b82f6'])
        ->assertNotFound();
});

it('nao pinta cartao de outro usuario', function (): void {
    $alheio = Card::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.cards.color', $alheio->id), ['color' => '#3b82f6'])
        ->assertNotFound();
});

it('esconde meses do ano', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('plan.months.hidden', 2026), ['months' => ['2026-01', '2026-02']])
        ->assertRedirect();

    expect(HiddenPlanMonth::query()->where('user_id', $user->id)
        ->orderBy('month')->pluck('month')
        ->map(fn ($m) => $m->format('Y-m'))->all())
        ->toBe(['2026-01', '2026-02']);
});

it('a lista substitui a anterior do mesmo ano', function (): void {
    $user = User::factory()->create();
    HiddenPlanMonth::factory()->for($user)->create(['month' => '2026-01-01']);
    HiddenPlanMonth::factory()->for($user)->create(['month' => '2026-05-01']);

    $this->actingAs($user)->put(route('plan.months.hidden', 2026), ['months' => ['2026-09']]);

    expect(HiddenPlanMonth::query()->where('user_id', $user->id)->pluck('month')
        ->map(fn ($m) => $m->format('Y-m'))->all())
        ->toBe(['2026-09']);
});

it('lista vazia mostra todos os meses de volta', function (): void {
    $user = User::factory()->create();
    HiddenPlanMonth::factory()->for($user)->create(['month' => '2026-01-01']);

    $this->actingAs($user)->put(route('plan.months.hidden', 2026), ['months' => []]);

    expect(HiddenPlanMonth::query()->count())->toBe(0);
});

it('nao mexe na escolha de outro ano', function (): void {
    $user = User::factory()->create();
    HiddenPlanMonth::factory()->for($user)->create(['month' => '2027-03-01']);

    $this->actingAs($user)->put(route('plan.months.hidden', 2026), ['months' => ['2026-01']]);

    expect(HiddenPlanMonth::query()->where('user_id', $user->id)->orderBy('month')->pluck('month')
        ->map(fn ($m) => $m->format('Y-m'))->all())
        ->toBe(['2026-01', '2027-03']);
});

it('recusa mes de outro ano na lista', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('plan.months.hidden', 2026), ['months' => ['2027-01']])
        ->assertSessionHasErrors('months.0');

    expect(HiddenPlanMonth::query()->count())->toBe(0);
});

it('recusa mes malformado na lista', function (): void {
    $this->actingAs(User::factory()->create())
        ->put(route('plan.months.hidden', 2026), ['months' => ['2026-13']])
        ->assertSessionHasErrors('months.0');
});

it('recusa a lista ausente para nao apagar por acidente', function (): void {
    $user = User::factory()->create();
    HiddenPlanMonth::factory()->for($user)->create(['month' => '2026-01-01']);

    $this->actingAs($user)
        ->put(route('plan.months.hidden', 2026), [])
        ->assertSessionHasErrors('months');

    expect(HiddenPlanMonth::query()->count())->toBe(1);
});

it('nao esconde meses na conta de outro usuario', function (): void {
    $alheio = User::factory()->create();
    HiddenPlanMonth::factory()->for($alheio)->create(['month' => '2026-01-01']);

    $this->actingAs(User::factory()->create())
        ->put(route('plan.months.hidden', 2026), ['months' => ['2026-06']]);

    expect(HiddenPlanMonth::query()->where('user_id', $alheio->id)->count())->toBe(1);
});

it('marca uma conta como paga no mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.paid', [$line->id, '2026-05']), ['paid' => true])
        ->assertRedirect();

    expect(BudgetLinePayment::query()->where('budget_line_id', $line->id)->count())->toBe(1);
});

it('marcar duas vezes nao cria duas marcas', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.paid', [$line->id, '2026-05']), ['paid' => true])
        ->assertRedirect();

    // A segunda chamada precisa ser bem-sucedida, nao apenas nao duplicar: sem
    // esta asserção o teste passaria tambem se ela estourasse a constraint e
    // devolvesse erro.
    $this->actingAs($user)
        ->put(route('plan.lines.paid', [$line->id, '2026-05']), ['paid' => true])
        ->assertRedirect();

    expect(BudgetLinePayment::query()->count())->toBe(1);
});

it('desmarca a conta como paga', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();
    BudgetLinePayment::factory()->for($line)->create(['month' => '2026-05-01']);

    $this->actingAs($user)->put(route('plan.lines.paid', [$line->id, '2026-05']), ['paid' => false]);

    expect(BudgetLinePayment::query()->count())->toBe(0);
});

it('marcar como paga nao fixa o valor do mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create(['default_amount_cents' => 400_00]);

    $this->actingAs($user)->put(route('plan.lines.paid', [$line->id, '2026-05']), ['paid' => true]);

    expect(BudgetLineAmount::query()->count())->toBe(0);
});

it('marca uma fatura de cartao como paga', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->put(route('plan.cards.paid', [$card->id, '2026-09']), ['paid' => true]);

    expect(CardPayment::query()->where('card_id', $card->id)->count())->toBe(1);
});

it('marcar fatura como paga nao cria correcao de valor', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->put(route('plan.cards.paid', [$card->id, '2026-09']), ['paid' => true]);

    expect(CardMonthAmount::query()->count())->toBe(0);
});

it('recusa paid ausente', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.paid', [$line->id, '2026-05']), [])
        ->assertSessionHasErrors('paid');
});

it('recusa mes malformado ao marcar como paga', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.paid', [$line->id, '2026-13']), ['paid' => true])
        ->assertNotFound();
});

it('nao marca conta de outro usuario como paga', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.lines.paid', [$alheia->id, '2026-05']), ['paid' => true])
        ->assertNotFound();

    expect(BudgetLinePayment::query()->count())->toBe(0);
});

it('aceita cor hexadecimal fora da paleta', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)->put(route('plan.lines.color', $line->id), ['color' => '#A1B2C3']);

    expect($line->fresh()->color)->toBe('#a1b2c3');
});

it('recusa cor que nao e hexadecimal', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.color', $line->id), ['color' => 'azul'])
        ->assertSessionHasErrors('color');
});
