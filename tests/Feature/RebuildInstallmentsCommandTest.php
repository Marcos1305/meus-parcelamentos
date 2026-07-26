<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

function installmentSnapshot(Purchase $purchase): array
{
    return $purchase->installments()->get()
        ->map(fn (Installment $installment): array => [
            'number' => $installment->number,
            'amount_cents' => $installment->amount_cents,
            'due_date' => $installment->due_date->toDateString(),
        ])
        ->all();
}

it('regenera parcelas identicas quando nada mudou', function (): void {
    $purchase = Purchase::factory()->parcelada(6)->create();
    $before = installmentSnapshot($purchase);

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect(installmentSnapshot($purchase))->toBe($before);
});

it('atualiza as datas depois de mudar o vencimento do cartao', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);
    $purchase = Purchase::factory()->parcelada(2)->for($card)->create(['purchased_at' => '2026-03-02']);

    $card->update(['due_day' => 25]);

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect($purchase->installments()->get()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-25', '2026-04-25']);
});

it('limita o rebuild a um usuario quando pedido', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $myCard = Card::factory()->for($mine)->create(['closing_day' => 3, 'due_day' => 10]);
    $theirCard = Card::factory()->for($theirs)->create(['closing_day' => 3, 'due_day' => 10]);

    $myPurchase = Purchase::factory()->parcelada(1)->for($myCard)->create(['purchased_at' => '2026-03-02']);
    $theirPurchase = Purchase::factory()->parcelada(1)->for($theirCard)->create(['purchased_at' => '2026-03-02']);

    $myCard->update(['due_day' => 25]);
    $theirCard->update(['due_day' => 25]);

    $this->artisan('installments:rebuild', ['--user' => $mine->id])->assertSuccessful();

    expect($myPurchase->installments()->first()->due_date->toDateString())->toBe('2026-03-25')
        ->and($theirPurchase->installments()->first()->due_date->toDateString())->toBe('2026-03-10');
});

it('informa quantas compras foram processadas', function (): void {
    Purchase::factory()->parcelada(3)->count(2)->create();

    $this->artisan('installments:rebuild')
        ->expectsOutputToContain('2 compras')
        ->assertSuccessful();
});

it('nao deixa parcelas orfas', function (): void {
    Purchase::factory()->parcelada(4)->create();

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect(Installment::count())->toBe(4);
});
