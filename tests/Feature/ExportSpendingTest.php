<?php

use App\Enums\ExpenseCategory;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;

function spendingCsvLines(string $csv): array
{
    return array_slice(explode("\n", trim($csv)), 1);
}

it('comeca com BOM e usa ponto e virgula', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']));
    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('data_compra;cartao;descricao;categoria;tipo;parcelas;valor');
});

it('nomeia o arquivo com o periodo', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->assertDownload('gastos-2026-07-01-a-2026-07-31.csv');
});

it('escreve a compra a vista com os valores corretos', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Nubank', 'last_four_digits' => '8291']);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 19_90,
        'purchased_at' => '2026-07-05',
        'billed_at' => '2026-08-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $csv = $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->streamedContent();

    expect(spendingCsvLines($csv))->toBe(['05/07/2026;"Nubank •••• 8291";"Padaria do Bairro";Mercado;"À vista";1;19,90']);
});

it('exporta a compra parcelada numa linha so com o valor total', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Nubank', 'last_four_digits' => '8291']);
    Purchase::factory()->for($card)->create([
        'description' => 'Sofá de canto',
        'total_amount_cents' => 1_200_00,
        'installments_count' => 10,
        'purchased_at' => '2026-07-10',
        'category' => null,
    ]);

    $csv = $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->streamedContent();

    expect(spendingCsvLines($csv))->toBe(['10/07/2026;"Nubank •••• 8291";"Sofá de canto";;Parcelada;10;1200,00']);
});

it('filtra pela data da compra incluindo os dois limites', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    CashPurchase::factory()->for($card)->create(['description' => 'Antes', 'purchased_at' => '2026-06-30']);
    CashPurchase::factory()->for($card)->create(['description' => 'Inicio', 'purchased_at' => '2026-07-01']);
    Purchase::factory()->for($card)->create(['description' => 'Fim', 'purchased_at' => '2026-07-31']);
    Purchase::factory()->for($card)->create(['description' => 'Depois', 'purchased_at' => '2026-08-01']);

    $csv = $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->streamedContent();

    expect($csv)->toContain('Inicio')
        ->and($csv)->toContain('Fim')
        ->and($csv)->not->toContain('Antes')
        ->and($csv)->not->toContain('Depois');
});

it('ordena as linhas pela data da compra', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    CashPurchase::factory()->for($card)->create(['description' => 'Terceira', 'purchased_at' => '2026-07-20']);
    Purchase::factory()->for($card)->create(['description' => 'Segunda', 'purchased_at' => '2026-07-10']);
    CashPurchase::factory()->for($card)->create(['description' => 'Primeira', 'purchased_at' => '2026-07-02']);

    $csv = $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->streamedContent();

    $descriptions = array_map(fn (string $line): string => str_getcsv($line, ';', '"', '')[2], spendingCsvLines($csv));

    expect($descriptions)->toBe(['Primeira', 'Segunda', 'Terceira']);
});

it('nao exporta gastos de outro usuario', function (): void {
    $user = User::factory()->create();
    $otherCard = Card::factory()->for(User::factory())->create();
    CashPurchase::factory()->for($otherCard)->create(['description' => 'Alheia', 'purchased_at' => '2026-07-05']);
    Purchase::factory()->for($otherCard)->create(['description' => 'Alheia parcelada', 'purchased_at' => '2026-07-05']);

    $csv = $this->actingAs($user)
        ->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->streamedContent();

    expect(spendingCsvLines($csv))->toBe([]);
});

it('recusa periodo invalido', function (array $query, string $field): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('spending.export', $query))
        ->assertSessionHasErrors($field);
})->with([
    'sem inicio' => [['ate' => '2026-07-31'], 'de'],
    'sem fim' => [['de' => '2026-07-01'], 'ate'],
    'fim antes do inicio' => [['de' => '2026-07-31', 'ate' => '2026-07-01'], 'ate'],
    'data malformada' => [['de' => '2026-13-01', 'ate' => '2026-07-31'], 'de'],
]);

it('exige autenticacao para exportar', function (): void {
    $this->get(route('spending.export', ['de' => '2026-07-01', 'ate' => '2026-07-31']))
        ->assertRedirect(route('login'));
});
