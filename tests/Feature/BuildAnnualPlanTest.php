<?php

use App\Actions\BuildAnnualPlan;
use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function planFor(User $user, int $year = 2026): array
{
    return app(BuildAnnualPlan::class)->handle($user, $year);
}

function parcelaNoCartao(Card $card, string $dueDate, int $amountCents): Installment
{
    return Installment::factory()
        ->for(Purchase::factory()->for($card))
        ->create(['number' => 1, 'due_date' => $dueDate, 'amount_cents' => $amountCents]);
}

it('devolve os doze meses do ano', function (): void {
    $plan = planFor(User::factory()->create());

    expect($plan['months'])->toBe([
        '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
        '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
    ])->and($plan['year'])->toBe(2026);
});

it('usa o padrao da linha no mes sem valor gravado', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->expense()->create([
        'name' => 'Energia',
        'default_amount_cents' => 400_00,
    ]);

    $plan = planFor($user);

    expect($plan['lines'][0]['months']['2026-05'])
        ->toBe(['amount_cents' => null, 'effective_cents' => 400_00]);
});

it('o valor gravado vence o padrao', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 400_00]);
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01', 'amount_cents' => 573_74]);

    $plan = planFor($user);

    expect($plan['lines'][0]['months']['2026-05'])
        ->toBe(['amount_cents' => 573_74, 'effective_cents' => 573_74])
        ->and($plan['lines'][0]['months']['2026-06']['effective_cents'])->toBe(400_00);
});

it('soma as parcelas do mes no valor calculado do cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 100_00);
    parcelaNoCartao($card, '2026-09-20', 250_00);
    parcelaNoCartao($card, '2026-10-10', 900_00);

    $plan = planFor($user);

    expect($plan['cards'][0]['months']['2026-09']['computed_cents'])->toBe(350_00)
        ->and($plan['cards'][0]['months']['2026-10']['computed_cents'])->toBe(900_00);
});

it('conta parcela ja vencida do mes passado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-02-10', 500_00);

    expect(planFor($user)['cards'][0]['months']['2026-02']['computed_cents'])->toBe(500_00);
});

it('a correcao vence o calculado sem apagar o calculado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 350_00);
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01', 'amount_cents' => 2_147_33]);

    expect(planFor($user)['cards'][0]['months']['2026-09'])->toBe([
        'computed_cents' => 350_00,
        'override_cents' => 2_147_33,
        'effective_cents' => 2_147_33,
    ]);
});

it('correcao de zero vale zero, nao volta para o calculado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 350_00);
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01', 'amount_cents' => 0]);

    expect(planFor($user)['cards'][0]['months']['2026-09']['effective_cents'])->toBe(0);
});

it('soma entrada, saida e saldo', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 14_500_00]);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 400_00]);
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 1_000_00);

    $totals = planFor($user)['totals']['2026-09'];

    expect($totals)->toBe([
        'income_cents' => 14_500_00,
        'expense_cents' => 1_400_00,
        'balance_cents' => 13_100_00,
    ]);
});

it('preserva saldo negativo', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 1_000_00]);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 2_500_00]);

    expect(planFor($user)['totals']['2026-09']['balance_cents'])->toBe(-1_500_00);
});

it('ignora parcela de outro usuario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    parcelaNoCartao(Card::factory()->for(User::factory()->create())->create(), '2026-09-10', 999_00);

    expect(planFor($user)['cards'][0]['months']['2026-09']['computed_cents'])->toBe(0);
});

it('ignora linha arquivada', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Energia']);
    BudgetLine::factory()->for($user)->archived()->create(['name' => 'Netflix']);

    expect(array_column(planFor($user)['lines'], 'name'))->toBe(['Energia']);
});

it('ignora parcela de outro ano', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2027-03-10', 800_00);

    $plan = planFor($user, 2026);

    expect(array_sum(array_column($plan['cards'][0]['months'], 'computed_cents')))->toBe(0);
});

it('ordena as linhas por posicao', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Agua', 'position' => 2]);
    BudgetLine::factory()->for($user)->create(['name' => 'Energia', 'position' => 1]);

    expect(array_column(planFor($user)['lines'], 'name'))->toBe(['Energia', 'Agua']);
});
