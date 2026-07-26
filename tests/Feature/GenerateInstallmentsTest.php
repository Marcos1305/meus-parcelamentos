<?php

use App\Actions\GenerateInstallments;
use App\Models\Card;
use App\Models\Purchase;

it('grava uma parcela por prestacao com valores e datas corretos', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);

    $purchase = Purchase::factory()->for($card)->create([
        'total_amount_cents' => 100_000,
        'installments_count' => 3,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    $installments = $purchase->installments()->get();

    expect($installments)->toHaveCount(3)
        ->and($installments->pluck('amount_cents')->all())->toBe([33334, 33333, 33333])
        ->and($installments->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('mantem a soma das parcelas igual ao total da compra', function (): void {
    $purchase = Purchase::factory()->create([
        'total_amount_cents' => 99_999,
        'installments_count' => 7,
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    expect((int) $purchase->installments()->sum('amount_cents'))->toBe(99_999);
});

it('e idempotente', function (): void {
    $purchase = Purchase::factory()->create(['installments_count' => 5]);

    $snapshot = fn (): array => $purchase->installments()->get()
        ->map(fn ($installment): array => [
            'number' => $installment->number,
            'amount_cents' => $installment->amount_cents,
            'due_date' => $installment->due_date->toDateString(),
        ])
        ->all();

    app(GenerateInstallments::class)->handle($purchase);
    $first = $snapshot();

    app(GenerateInstallments::class)->handle($purchase);
    $second = $snapshot();

    expect($purchase->installments()->count())->toBe(5)
        ->and($second)->toBe($first);
});

it('reflete a mudanca de vencimento do cartao ao regerar', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);
    $purchase = Purchase::factory()->for($card)->create([
        'installments_count' => 2,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    $card->update(['due_day' => 20]);
    app(GenerateInstallments::class)->handle($purchase->fresh());

    expect($purchase->installments()->get()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-20', '2026-04-20']);
});
