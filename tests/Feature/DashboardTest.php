<?php

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildMonthlyProjection;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function installmentOn(User $user, string $dueDate, int $amountCents, int $number = 1): Installment
{
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create();

    return Installment::factory()->for($purchase)->create([
        'number' => $number,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

it('resume apenas as parcelas do usuario', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-08-20', 200_00);
    installmentOn(User::factory()->create(), '2026-08-20', 999_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['total_due_cents'])->toBe(300_00)
        ->and($summary['open_installments_count'])->toBe(2)
        ->and($summary['cards_count'])->toBe(2);
});

it('ignora parcelas vencidas no total devido', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-06-20', 500_00);
    installmentOn($user, '2026-08-20', 100_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['total_due_cents'])->toBe(100_00)
        ->and($summary['open_installments_count'])->toBe(1);
});

it('soma o proximo vencimento agrupando parcelas da mesma data', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-07-20', 250_00);
    installmentOn($user, '2026-08-20', 900_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['next_due_date'])->toBe('2026-07-20')
        ->and($summary['next_due_cents'])->toBe(350_00);
});

it('devolve resumo zerado quando nao ha nada', function (): void {
    $summary = app(BuildDashboardSummary::class)->handle(User::factory()->create());

    expect($summary)->toBe([
        'cards_count' => 0,
        'open_installments_count' => 0,
        'total_due_cents' => 0,
        'next_due_date' => null,
        'next_due_cents' => 0,
    ]);
});

it('projeta seis meses a partir do corrente', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-09-20', 300_00);

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect($projection)->toHaveCount(6)
        ->and(array_column($projection, 'month'))
        ->toBe(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'])
        ->and(array_column($projection, 'total_cents'))->toBe([100_00, 0, 300_00, 0, 0, 0]);
});

it('exclui da projecao o que ja venceu no mes corrente', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-01', 500_00);
    installmentOn($user, '2026-07-20', 100_00);

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect($projection[0]['total_cents'])->toBe(100_00);
});

it('atravessa a virada de ano na projecao', function (): void {
    $this->travelTo('2026-11-15');
    $user = User::factory()->create();

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect(array_column($projection, 'month'))
        ->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04']);
});

it('entrega o resumo e a projecao para a tela', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-08-20', 100_00);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('summary')
            ->has('projection', 6));
});
