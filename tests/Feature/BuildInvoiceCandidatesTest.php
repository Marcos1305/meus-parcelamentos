<?php

use App\Actions\GenerateInstallments;
use App\Invoices\BuildInvoiceCandidates;
use App\Invoices\CandidateStatus;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Models\Card;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;

function parsedInvoice(string $dueDate, ParsedInvoiceLine ...$lines): ParsedInvoice
{
    return new ParsedInvoice(
        issuer: 'nubank',
        dueDate: CarbonImmutable::parse($dueDate),
        lastFourDigits: '8291',
        lines: $lines,
    );
}

function parsedLine(int $number, int $count, int $day, int $amountCents): ParsedInvoiceLine
{
    return new ParsedInvoiceLine(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        transactionDay: $day,
        installmentNumber: $number,
        installmentsCount: $count,
        amountCents: $amountCents,
    );
}

it('monta um candidato por linha', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990), parsedLine(1, 4, 2, 5_000)),
        $card,
    );

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->status)->toBe(CandidateStatus::Matched)
        ->and($candidates[0]->totalAmountCents)->toBe(129_900)
        ->and($candidates[1]->totalAmountCents)->toBe(20_000);
});

it('marca como duplicata a compra que ja existe', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $purchase = Purchase::create([
        'card_id' => $card->id,
        'description' => 'Mercado Livre',
        'merchant' => 'Mercado Livre',
        'total_amount_cents' => 129_900,
        'installments_count' => 10,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase->load('card'));

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990)),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Duplicate);
});

it('marca como duplicata a segunda linha identica dentro da mesma fatura', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice(
            '2026-05-10',
            parsedLine(3, 10, 2, 12_990),
            parsedLine(3, 10, 2, 12_990),
        ),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Matched)
        ->and($candidates[1]->status)->toBe(CandidateStatus::Duplicate);
});

it('nao marca duplicata quando as linhas resolvem para compras distintas', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice(
            '2026-05-10',
            parsedLine(3, 10, 2, 12_990),
            parsedLine(3, 10, 2, 12_991),
        ),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Matched)
        ->and($candidates[1]->status)->toBe(CandidateStatus::Matched);
});

it('nao confunde compra de outro cartao com duplicata', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);
    $other = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);

    Purchase::create([
        'card_id' => $other->id,
        'description' => 'Mercado Livre',
        'merchant' => 'Mercado Livre',
        'total_amount_cents' => 129_900,
        'installments_count' => 10,
        'purchased_at' => '2026-03-02',
    ]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990)),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Matched);
});
