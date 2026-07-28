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

/**
 * O sinal de menos desta fatura e o U+2212, nao o hifen ASCII. Um estorno
 * parcelado abateria o valor em vez de cobra-lo, entao a linha e descartada.
 */
it('descarta uma linha parcelada com valor negativo', function (): void {
    $text = "Data de vencimento: 09 JUL 2026\n02 JUN   •••• 5563   Mp *Petinolutuluvagov - Parcela 4/6      \u{2212}R\$ 44,04\n";

    expect((new NubankInvoiceParser)->parse($text)->lines)->toHaveCount(0);
});

it('converte um valor na casa dos milhares corretamente', function (): void {
    $text = "Data de vencimento: 09 JUL 2026\n02 JUN   •••• 5563   Mp *Petinolutuluvagov - Parcela 4/6      R\$ 1.234,56\n";

    $lines = (new NubankInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amountCents)->toBe(123_456);
});

/**
 * O numero do cartao virtual e opcional na linha: a fatura lista varios e nem
 * toda compra parcelada carrega o seu.
 */
it('extrai uma linha parcelada sem o numero do cartao', function (): void {
    $text = "Data de vencimento: 09 JUL 2026\n02 JUN   Mp *Petinolutuluvagov - Parcela 4/6      R\$ 44,04\n";

    $lines = (new NubankInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->description)->toBe('Mp *Petinolutuluvagov')
        ->and($lines[0]->installmentNumber)->toBe(4)
        ->and($lines[0]->installmentsCount)->toBe(6)
        ->and($lines[0]->amountCents)->toBe(4_404);
});

it('extrai as compras a vista', function (): void {
    $cashLines = (new NubankInvoiceParser)->parse(nubankFixture())->cashLines;

    expect($cashLines)->toHaveCount(53);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->transactionDay)->toBeBetween(1, 31)
            ->and($line->transactionMonth)->toBeBetween(1, 12)
            ->and($line->description)->not->toContain('Parcela');
    }
});

it('nao confunde parcelada com a vista', function (): void {
    $invoice = (new NubankInvoiceParser)->parse(nubankFixture());

    $cashDescriptions = array_map(
        fn ($line): string => $line->description,
        $invoice->cashLines,
    );

    foreach ($invoice->lines as $installment) {
        expect($cashDescriptions)->not->toContain($installment->description);
    }
});

it('ignora pagamento recebido e saldo remanescente', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new NubankInvoiceParser)->parse(nubankFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('pagamento recebido')
            ->and($description)->not->toContain('saldo restante');
    }
});
