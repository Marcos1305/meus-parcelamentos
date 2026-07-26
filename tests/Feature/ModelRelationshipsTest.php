<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('apaga compras e parcelas ao apagar o cartao', function (): void {
    $card = Card::factory()->create();
    Purchase::factory()->parcelada(6)->for($card)->create();

    expect(Installment::count())->toBe(6);

    $card->delete();

    expect(Purchase::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('apaga parcelas ao apagar a compra', function (): void {
    $purchase = Purchase::factory()->parcelada(4)->create();

    $purchase->delete();

    expect(Installment::count())->toBe(0);
});

it('alcanca as parcelas do cartao por hasManyThrough', function (): void {
    $card = Card::factory()->create();
    Purchase::factory()->parcelada(3)->for($card)->create();
    Purchase::factory()->parcelada(2)->for($card)->create();

    expect($card->installments()->count())->toBe(5);
});

it('filtra parcelas pelo dono', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Purchase::factory()->parcelada(3)->for(Card::factory()->for($mine))->create();
    Purchase::factory()->parcelada(5)->for(Card::factory()->for($theirs))->create();

    expect(Installment::query()->forUser($mine)->count())->toBe(3);
});

it('impede duas parcelas com o mesmo numero na mesma compra', function (): void {
    $purchase = Purchase::factory()->parcelada(2)->create();

    Installment::factory()->for($purchase)->create(['number' => 1]);
})->throws(UniqueConstraintViolationException::class);

it('separa parcelas em aberto das pagas', function (): void {
    $purchase = Purchase::factory()->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => now()->subMonth()]);
    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => now()->addMonth()]);

    expect(Installment::query()->paid()->count())->toBe(1)
        ->and(Installment::query()->open()->count())->toBe(1);
});
