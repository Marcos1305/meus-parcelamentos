<?php

use App\Actions\BuildCardProjection;
use App\Actions\BuildIncomeCommitment;
use App\Actions\BuildPayoffCurve;
use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\CashPurchase;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function parcelaDoGrafico(Card $card, string $dueDate, int $amountCents): Installment
{
    return Installment::factory()
        ->for(Purchase::factory()->for($card))
        ->create(['number' => 1, 'due_date' => $dueDate, 'amount_cents' => $amountCents]);
}

it('compara cartoes e despesas fixas com a entrada de cada mes', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaDoGrafico($card, '2026-07-20', 300_00);
    parcelaDoGrafico($card, '2026-08-20', 200_00);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 1_000_00]);
    BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 5_000_00]);

    $commitment = app(BuildIncomeCommitment::class)->handle($user);

    expect($commitment)->toHaveCount(6)
        ->and($commitment[0])->toBe([
            'month' => '2026-07',
            'card_cents' => 300_00,
            'fixed_expense_cents' => 1_000_00,
            'income_cents' => 5_000_00,
        ])
        ->and($commitment[1]['card_cents'])->toBe(200_00);
});

it('usa a fatura do planejamento no lugar das parcelas quando informada', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaDoGrafico($card, '2026-08-20', 200_00);
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-08-01', 'amount_cents' => 1_250_00]);

    $commitment = app(BuildIncomeCommitment::class)->handle($user);

    expect($commitment[1]['card_cents'])->toBe(1_250_00);
});

it('conta a parcela que ja venceu no mes corrente como parte da fatura', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaDoGrafico($card, '2026-07-01', 400_00);

    expect(app(BuildIncomeCommitment::class)->handle($user)[0]['card_cents'])->toBe(400_00);
});

it('marca a entrada como nao informada quando o mes nao tem valor', function (): void {
    $user = User::factory()->create();
    $income = BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 0]);
    BudgetLineAmount::factory()->for($income)->create(['month' => '2026-08-01', 'amount_cents' => 4_000_00]);

    $commitment = app(BuildIncomeCommitment::class)->handle($user);

    expect(array_column($commitment, 'income_cents'))
        ->toBe([null, 4_000_00, null, null, null, null]);
});

it('atravessa a virada de ano no comprometimento', function (): void {
    $this->travelTo('2026-11-15');
    $user = User::factory()->create();
    $income = BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 0]);
    BudgetLineAmount::factory()->for($income)->create(['month' => '2027-02-01', 'amount_cents' => 3_000_00]);

    $commitment = app(BuildIncomeCommitment::class)->handle($user);

    expect(array_column($commitment, 'month'))
        ->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04'])
        ->and($commitment[3]['income_cents'])->toBe(3_000_00);
});

it('restringe so a camada de cartao ao cartao escolhido', function (): void {
    $user = User::factory()->create();
    $nubank = Card::factory()->for($user)->create();
    $itau = Card::factory()->for($user)->create();
    parcelaDoGrafico($nubank, '2026-07-20', 100_00);
    parcelaDoGrafico($itau, '2026-07-20', 900_00);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 1_000_00]);

    $commitment = app(BuildIncomeCommitment::class)->handle($user, $nubank);

    expect($commitment[0]['card_cents'])->toBe(100_00)
        ->and($commitment[0]['fixed_expense_cents'])->toBe(1_000_00);
});

it('mostra o saldo devedor caindo ate zerar', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaDoGrafico($card, '2026-07-01', 999_00);
    parcelaDoGrafico($card, '2026-07-20', 100_00);
    parcelaDoGrafico($card, '2026-08-20', 100_00);
    parcelaDoGrafico($card, '2026-10-20', 300_00);

    $curve = app(BuildPayoffCurve::class)->handle($user);

    expect($curve)->toBe([
        ['month' => '2026-07', 'remaining_cents' => 500_00],
        ['month' => '2026-08', 'remaining_cents' => 400_00],
        ['month' => '2026-09', 'remaining_cents' => 300_00],
        ['month' => '2026-10', 'remaining_cents' => 300_00],
        ['month' => '2026-11', 'remaining_cents' => 0],
    ]);
});

it('devolve curva vazia quando nao ha parcela em aberto', function (): void {
    $user = User::factory()->create();
    parcelaDoGrafico(Card::factory()->for($user)->create(), '2026-06-20', 100_00);

    expect(app(BuildPayoffCurve::class)->handle($user))->toBe([]);
});

it('restringe a curva de quitacao ao cartao escolhido', function (): void {
    $user = User::factory()->create();
    $nubank = Card::factory()->for($user)->create();
    $itau = Card::factory()->for($user)->create();
    parcelaDoGrafico($nubank, '2026-08-20', 100_00);
    parcelaDoGrafico($itau, '2027-03-20', 900_00);

    expect(app(BuildPayoffCurve::class)->handle($user, $nubank))->toBe([
        ['month' => '2026-07', 'remaining_cents' => 100_00],
        ['month' => '2026-08', 'remaining_cents' => 100_00],
        ['month' => '2026-09', 'remaining_cents' => 0],
    ]);
});

it('separa a projecao pelos quatro cartoes com mais parcelas e agrupa o resto', function (): void {
    $user = User::factory()->create();
    $cards = collect([500, 400, 300, 200, 100, 50])->map(function (int $reais, int $index) use ($user): Card {
        $card = Card::factory()->for($user)->create(['name' => "Cartao {$index}"]);
        parcelaDoGrafico($card, '2026-08-20', $reais * 100);

        return $card;
    });

    $projection = app(BuildCardProjection::class)->handle($user);

    expect(array_column($projection['series'], 'label'))
        ->toBe(['Cartao 0', 'Cartao 1', 'Cartao 2', 'Cartao 3', 'Outros'])
        ->and($projection['months'])->toHaveCount(6)
        ->and($projection['months'][1]['totals'])->toBe([
            "card-{$cards[0]->id}" => 500_00,
            "card-{$cards[1]->id}" => 400_00,
            "card-{$cards[2]->id}" => 300_00,
            "card-{$cards[3]->id}" => 200_00,
            'outros' => 150_00,
        ]);
});

it('nao cria o grupo outros quando ha ate quatro cartoes com parcelas', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Nubank']);
    Card::factory()->for($user)->create(['name' => 'Sem parcelas']);
    parcelaDoGrafico($card, '2026-07-20', 100_00);
    parcelaDoGrafico($card, '2026-07-01', 999_00);
    parcelaDoGrafico(Card::factory()->for(User::factory()->create())->create(), '2026-07-20', 999_00);

    $projection = app(BuildCardProjection::class)->handle($user);

    expect($projection['series'])->toBe([['key' => "card-{$card->id}", 'label' => 'Nubank']])
        ->and($projection['months'][0]['totals'])->toBe(["card-{$card->id}" => 100_00]);
});

it('entrega os graficos de todos os cartoes quando nao ha filtro', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaDoGrafico($card, '2026-08-20', 100_00);
    CashPurchase::factory()->for($card)->create([
        'purchased_at' => '2026-06-20',
        'billed_at' => '2026-07-10',
        'amount_cents' => 80_00,
        'category' => 'mercado',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commitment', 6)
            ->has('payoff', 3)
            ->has('card_projection.series', 1)
            ->where('categories.0.category', 'mercado')
            ->where('categories.0.total_cents', 80_00));
});

it('omite os graficos de todos os cartoes quando um cartao esta filtrado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['card' => $card->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commitment', 6)
            ->where('card_projection', null)
            ->where('categories', null));
});
