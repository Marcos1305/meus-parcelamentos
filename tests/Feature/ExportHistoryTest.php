<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function csvFrom(TestResponse $response): string
{
    return $response->streamedContent();
}

function exportInstallment(User $user, string $dueDate, int $amountCents, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()->for($card ?? Card::factory()->for($user))->create([
        'description' => 'MacBook Pro M2',
        'merchant' => 'Apple Store',
        'total_amount_cents' => 1_200_00,
        'installments_count' => 12,
    ]);

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('comeca com BOM e usa ponto e virgula', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);

    $response = $this->actingAs($user)->get(route('history.export'));
    $response->assertOk();

    $csv = csvFrom($response);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('cartao;descricao;estabelecimento;valor_total_compra;parcela;total_parcelas;valor_parcela;vencimento;status');
});

it('exporta uma linha por parcela do filtro ativo', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);
    exportInstallment($user, '2026-11-10', 100_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export')));

    expect(substr_count(trim($csv), "\n"))->toBe(1);
});

it('respeita o filtro de todos os meses', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);
    exportInstallment($user, '2026-11-10', 100_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export', ['month' => 'all'])));

    expect(substr_count(trim($csv), "\n"))->toBe(2);
});

it('escreve os campos com os valores corretos', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Nubank', 'last_four_digits' => '8291']);
    exportInstallment($user, '2026-07-10', 100_00, $card);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export')));

    expect($csv)->toContain('"Nubank •••• 8291";"MacBook Pro M2";"Apple Store";1200,00;1;12;100,00;10/07/2026;Pago');
});

it('nao exporta parcelas de outro usuario', function (): void {
    $user = User::factory()->create();
    exportInstallment(User::factory()->create(), '2026-07-10', 999_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export', ['month' => 'all'])));

    expect(trim($csv))->not->toContain('999');
});

it('exige autenticacao para exportar', function (): void {
    $this->get(route('history.export'))->assertRedirect(route('login'));
});
