<?php

use App\Invoices\Parsers\MercadoPagoInvoiceParser;
use App\Invoices\UnreadableInvoiceException;

function mercadoPagoFixture(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/faturas/mercado-pago.txt');
}

it('reconhece a fatura do mercado pago', function (): void {
    expect((new MercadoPagoInvoiceParser)->supports(mercadoPagoFixture()))->toBeTrue();
});

it('nao reconhece fatura de outro emissor', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    expect((new MercadoPagoInvoiceParser)->supports($text))->toBeFalse();
})->with(['itau.txt', 'renner.txt', 'nubank.txt']);

it('extrai o vencimento e o emissor', function (): void {
    $invoice = (new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture());

    expect($invoice->dueDate->toDateString())->toBe('2026-07-10')
        ->and($invoice->issuer)->toBe('Mercado Pago');
});

it('nao identifica um cartao unico porque a fatura tem cinco', function (): void {
    expect((new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture())->lastFourDigits)->toBeNull();
});

it('extrai apenas as linhas parceladas', function (): void {
    $lines = (new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture())->lines;

    expect($lines)->toHaveCount(22);

    foreach ($lines as $line) {
        expect($line->installmentsCount)->toBeGreaterThan(0)
            ->and($line->installmentNumber)->toBeLessThanOrEqual($line->installmentsCount)
            ->and($line->amountCents)->toBeGreaterThan(0);
    }
});

it('extrai os campos de uma linha parcelada', function (): void {
    $lines = (new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture())->lines;

    expect($lines[0]->installmentNumber)->toBe(17)
        ->and($lines[0]->installmentsCount)->toBe(18)
        ->and($lines[0]->amountCents)->toBe(3_841)
        ->and($lines[0]->transactionDay)->toBe(13)
        ->and($lines[0]->description)->not->toContain('Parcela')->not->toContain('R$');
});

it('recusa texto sem vencimento', function (): void {
    expect(fn () => (new MercadoPagoInvoiceParser)->parse('Mercado Pago sem data'))
        ->toThrow(UnreadableInvoiceException::class);
});

it('converte um valor na casa dos milhares corretamente', function (): void {
    $text = "Vencimento: 10/07/2026\n13/02      COMERCIO TESTE                        Parcela 1 de 2       R\$ 1.234,56\n";

    $lines = (new MercadoPagoInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amountCents)->toBe(123_456);
});

it('descarta uma linha com valor negativo', function (): void {
    $text = "Vencimento: 10/07/2026\n13/02      COMERCIO TESTE                        Parcela 1 de 2       -R\$ 38,41\n";

    $lines = (new MercadoPagoInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(0);
});

it('nao extrai linha da prosa explicativa sobre parcelamento', function (): void {
    $text = <<<'TEXT'
    Vencimento: 10/07/2026

    Vonimigorima de fatura
    Lidotiv a gilifune parcela vemed, as próximas
    Parcele ou pague qualquer valor
    Selecione "Parcelar"
    TEXT;

    $lines = (new MercadoPagoInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(0);
});
