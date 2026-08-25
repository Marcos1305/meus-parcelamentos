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

it('nao repete nem perde parcelas entre paginas quando o vencimento empata', function (): void {
    // O desempate por `id` e o que garante paginas disjuntas: sem ele, duas
    // parcelas com o mesmo valor na coluna ordenada podem trocar de posicao
    // entre requisicoes e uma linha some sem nunca ser vista.
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    for ($i = 1; $i <= 25; $i++) {
        installmentFor($user, '2026-07-10', 100_00, $card);
    }

    $page1Ids = $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'page' => 1]))
        ->viewData('page')['props']['installments']['data'];

    $page2Ids = $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'page' => 2]))
        ->viewData('page')['props']['installments']['data'];

    $allIds = collect($page1Ids)->pluck('id')->merge(collect($page2Ids)->pluck('id'));

    expect($allIds)->toHaveCount(25);
    expect($allIds->unique())->toHaveCount(25);
});

it('nao repete nem perde parcelas entre paginas quando a subconsulta empata', function (): void {
    // Mesmo invariante do teste acima, mas para uma coluna ordenada por
    // subconsulta correlacionada: a subconsulta devolve o mesmo valor para
    // as 25 parcelas (mesma descricao), entao e o cenario onde o empate e
    // mais provavel de acontecer.
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    for ($i = 1; $i <= 25; $i++) {
        purchaseInstallment($user, '2026-07-10', 100_00, ['description' => 'Empate'], $card);
    }

    $page1Ids = $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'sort' => 'description', 'page' => 1]))
        ->viewData('page')['props']['installments']['data'];

    $page2Ids = $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'sort' => 'description', 'page' => 2]))
        ->viewData('page')['props']['installments']['data'];

    $allIds = collect($page1Ids)->pluck('id')->merge(collect($page2Ids)->pluck('id'));

    expect($allIds)->toHaveCount(25);
    expect($allIds->unique())->toHaveCount(25);
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

it('trata os curingas do like como texto literal na busca', function (string $termo, string $esperado): void {
    // Sem escapar, `%` e `_` valeriam como curinga aqui e como caractere
    // literal em Gastos (str_contains): a mesma busca devolveria listas
    // diferentes nas duas telas.
    $user = historyUser();

    foreach (['100% algodao', '100 reais', 'Pizza_grande', 'Pizzaxgrande'] as $descricao) {
        purchaseInstallment($user, '2026-07-10', 100_00, [
            'description' => $descricao,
            'merchant' => null,
            'notes' => null,
        ]);
    }

    $this->actingAs($user)
        ->get(route('history.index', ['q' => $termo]))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 1)
            ->where('installments.data.0.purchase.description', $esperado));
})->with([
    'porcento nao e curinga' => ['100%', '100% algodao'],
    'underline nao e curinga' => ['Pizza_grande', 'Pizza_grande'],
]);

it('nao dobra caixa em letra acentuada, mas dobra na parte ascii do termo', function (string $termo, int $esperado): void {
    // Limitacao conhecida e deliberada: o LIKE do SQLite dobra caixa so em
    // ASCII, entao no Historico a busca perde tambem a insensibilidade a
    // maiuscula quando a letra e acentuada. Em Gastos o casamento e em PHP
    // (mb_strtolower) e o mesmo termo funciona — ver o par deste teste em
    // BuildSpendingBreakdownTest.
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, [
        'description' => 'Açaí da esquina',
        'merchant' => null,
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => $termo]))
        ->assertInertia(fn ($page) => $page->has('installments.data', $esperado));
})->with([
    'termo igual ao texto casa' => ['Açaí', 1],
    'maiuscula acentuada nao casa' => ['AÇAÍ', 0],
    'acento continua sem dobrar' => ['acai', 0],
    'parte ascii do termo dobra normalmente' => ['DA ESQUINA', 1],
]);

it('devolve lista vazia quando a busca nao casa nada', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'zzzzzz']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('installments.data', 0));
});

function purchaseInstallment(User $user, string $dueDate, int $amountCents, array $purchaseAttributes, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()
        ->for($card ?? Card::factory()->for($user))
        ->create($purchaseAttributes);

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('ordena por descricao', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['description' => 'Zebra']);
    purchaseInstallment($user, '2026-07-11', 100_00, ['description' => 'Abacate']);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.description', 'Abacate'));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.description', 'Zebra'));
});

it('ordena por descricao ignorando caixa', function (): void {
    // SQLite compara texto por bytes: sem tratamento, minusculas (banana)
    // ficam depois de qualquer maiuscula (Zebra) numa ordenacao asc.
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['description' => 'Zebra']);
    purchaseInstallment($user, '2026-07-11', 100_00, ['description' => 'banana']);
    purchaseInstallment($user, '2026-07-12', 100_00, ['description' => 'Abacate']);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.purchase.description', 'Abacate')
            ->where('installments.data.1.purchase.description', 'banana')
            ->where('installments.data.2.purchase.description', 'Zebra'));
});

it('ordena descricao sem ordenacao natural', function (): void {
    // A ordenacao acontece no SQL porque a lista e paginada no servidor, e o
    // SQLite nao faz ordenacao natural: "Item 10" vem antes de "Item 2". A
    // tela de Gastos ordena em PHP e casa com esta regra de proposito — ver o
    // par deste teste em BuildSpendingBreakdownTest.
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['description' => 'Item 9']);
    purchaseInstallment($user, '2026-07-11', 100_00, ['description' => 'Item 10']);
    purchaseInstallment($user, '2026-07-12', 100_00, ['description' => 'Item 2']);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.purchase.description', 'Item 10')
            ->where('installments.data.1.purchase.description', 'Item 2')
            ->where('installments.data.2.purchase.description', 'Item 9'));
});

it('ordena por valor total da compra', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['total_amount_cents' => 50_00]);
    purchaseInstallment($user, '2026-07-11', 100_00, ['total_amount_cents' => 900_00]);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'total', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.total_amount_cents', 900_00));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'total', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.total_amount_cents', 50_00));
});

it('ordena por nome do cartao', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, [], Card::factory()->for($user)->create(['name' => 'Zeta']));
    purchaseInstallment($user, '2026-07-11', 100_00, [], Card::factory()->for($user)->create(['name' => 'Alfa']));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'card', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.card.name', 'Alfa'));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'card', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.card.name', 'Zeta'));
});

it('ordena por nome do cartao ignorando caixa', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, [], Card::factory()->for($user)->create(['name' => 'Zeta']));
    purchaseInstallment($user, '2026-07-11', 100_00, [], Card::factory()->for($user)->create(['name' => 'alfa']));
    purchaseInstallment($user, '2026-07-12', 100_00, [], Card::factory()->for($user)->create(['name' => 'Beta']));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'card', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.card.name', 'alfa')
            ->where('installments.data.1.card.name', 'Beta')
            ->where('installments.data.2.card.name', 'Zeta'));
});

it('ordena pelo numero da parcela', function (): void {
    $user = historyUser();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['installments_count' => 3]);
    Installment::factory()->for($purchase)->create(['number' => 3, 'due_date' => '2026-07-05', 'amount_cents' => 100_00]);
    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-07-20', 'amount_cents' => 100_00]);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'number', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.number', 1));
});

it('ordena por vencimento descendente quando pedido', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-02', 100_00);
    installmentFor($user, '2026-07-28', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'due_date', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.due_date', '2026-07-28'));
});

it('cai em vencimento ascendente quando sort ou dir sao invalidos', function (array $query): void {
    $user = historyUser();
    installmentFor($user, '2026-07-28', 100_00);
    installmentFor($user, '2026-07-02', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', $query))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.due_date', '2026-07-02')
            ->where('filters.sort', 'due_date')
            ->where('filters.dir', 'asc'));
})->with([
    [['sort' => 'status']],
    [['sort' => 'nao-existe']],
    [['dir' => 'sideways']],
    [['sort' => 'due_date', 'dir' => '']],
]);

it('degrada entrada hostil de q na url para busca vazia', function (mixed $q): void {
    // Parametro de URL invalido cai no default em silencio; cada forma
    // hostil de entrada precisa de uma prova, nao so leitura de codigo.
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor($user, '2026-07-11', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => $q]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 2)
            ->where('filters.q', ''));
})->with([
    'sintaxe de array na url degrada para busca vazia' => [['a', 'b']],
    'string so com espacos nao conta como busca ativa' => ['   '],
    'string vazia' => [''],
]);
