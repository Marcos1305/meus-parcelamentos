<?php

use App\Invoices\Parsers\NubankInvoiceParser;
use App\Invoices\UnreadableInvoiceException;

function nubankFixture(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/faturas/nubank.txt');
}

it('reconhece a fatura do nubank', function (): void {
    expect((new NubankInvoiceParser)->supports(nubankFixture()))->toBeTrue();
});

it('nao reconhece fatura de outro emissor', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    expect((new NubankInvoiceParser)->supports($text))->toBeFalse();
})->with(['itau.txt', 'renner.txt', 'mercado-pago.txt']);

it('extrai o vencimento e o emissor', function (): void {
    $invoice = (new NubankInvoiceParser)->parse(nubankFixture());

    expect($invoice->dueDate->toDateString())->toBe('2026-07-09')
        ->and($invoice->issuer)->toBe('Nubank');
});

it('nao identifica um cartao unico porque a fatura tem varios', function (): void {
    expect((new NubankInvoiceParser)->parse(nubankFixture())->lastFourDigits)->toBeNull();
});

it('extrai apenas as linhas parceladas', function (): void {
    $lines = (new NubankInvoiceParser)->parse(nubankFixture())->lines;

    expect($lines)->toHaveCount(20);

    foreach ($lines as $line) {
        expect($line->installmentsCount)->toBeGreaterThan(0)
            ->and($line->installmentNumber)->toBeLessThanOrEqual($line->installmentsCount)
            ->and($line->amountCents)->toBeGreaterThan(0);
    }
});

it('extrai os campos de uma linha parcelada', function (): void {
    $lines = (new NubankInvoiceParser)->parse(nubankFixture())->lines;

    expect($lines[0]->installmentNumber)->toBe(4)
        ->and($lines[0]->installmentsCount)->toBe(6)
        ->and($lines[0]->amountCents)->toBe(4_404)
        ->and($lines[0]->transactionDay)->toBe(2)
        ->and($lines[0]->description)->not->toContain('Parcela')->not->toContain('R$');
});

it('mantem as duas ocorrencias legitimas da mesma assinatura sem deduplicar', function (): void {
    $lines = (new NubankInvoiceParser)->parse(nubankFixture())->lines;

    $matching = array_filter(
        $lines,
        fn ($line): bool => $line->installmentNumber === 6 && $line->installmentsCount === 12 && $line->amountCents === 2_000,
    );

    expect($matching)->toHaveCount(2);
});

it('recusa texto sem vencimento', function (): void {
    expect(fn () => (new NubankInvoiceParser)->parse('Nu Pagamentos S.A. sem data'))
        ->toThrow(UnreadableInvoiceException::class);
});
