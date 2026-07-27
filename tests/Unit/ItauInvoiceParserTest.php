<?php

use App\Invoices\Parsers\ItauInvoiceParser;
use App\Invoices\UnreadableInvoiceException;

function itauFixture(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/faturas/itau.txt');
}

it('reconhece a fatura do itau', function (): void {
    expect((new ItauInvoiceParser)->supports(itauFixture()))->toBeTrue();
});

it('nao reconhece fatura de outro emissor', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    expect((new ItauInvoiceParser)->supports($text))->toBeFalse();
})->with(['nubank.txt', 'renner.txt', 'mercado-pago.txt']);

it('extrai o vencimento e o emissor', function (): void {
    $invoice = (new ItauInvoiceParser)->parse(itauFixture());

    expect($invoice->dueDate->toDateString())->toBe('2026-07-09')
        ->and($invoice->issuer)->toBe('Itaú');
});

it('extrai os quatro ultimos digitos do cartao', function (): void {
    expect((new ItauInvoiceParser)->parse(itauFixture())->lastFourDigits)->toBe('5687');
});

it('extrai apenas as linhas parceladas', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;

    expect($lines)->toHaveCount(19);

    foreach ($lines as $line) {
        expect($line->installmentsCount)->toBeGreaterThan(0)
            ->and($line->installmentNumber)->toBeLessThanOrEqual($line->installmentsCount)
            ->and($line->amountCents)->toBeGreaterThan(0);
    }
});

it('extrai os campos de uma linha parcelada', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;

    expect($lines[0]->transactionDay)->toBe(18)
        ->and($lines[0]->installmentNumber)->toBe(2)
        ->and($lines[0]->installmentsCount)->toBe(2)
        ->and($lines[0]->amountCents)->toBe(11_130)
        ->and($lines[0]->description)->toBe('GOFUPI *SapalE');
});

it('recusa texto sem vencimento', function (): void {
    expect(fn () => (new ItauInvoiceParser)->parse('ITAU UNIBANCO sem data'))
        ->toThrow(UnreadableInvoiceException::class);
});

it('extrai as duas transacoes de uma linha de coluna dupla', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;
    $valores = array_map(fn ($line): int => $line->amountCents, $lines);

    expect($valores)->toContain(11_130);
});

it('ignora a secao de proximas faturas', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;

    foreach ($lines as $line) {
        $parcelamentoProjetado = $line->transactionDay === 21
            && $line->installmentNumber === 10
            && $line->installmentsCount === 10;

        expect($parcelamentoProjetado)->toBeFalse()
            ->and($line->amountCents)->not->toBe(11_326);
    }

    expect($lines)->toHaveCount(19);
});

it('converte um valor na casa dos milhares corretamente', function (): void {
    $text = "Vencimento: 09/07/2026\n                 13/02    COMERCIO TESTE 01/02                             1.234,56\n";

    $lines = (new ItauInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amountCents)->toBe(123_456);
});

it('descarta uma linha com valor negativo', function (): void {
    $text = "Vencimento: 09/07/2026\n                 13/02    COMERCIO TESTE 01/02                             -1.234,56\n";

    $lines = (new ItauInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(0);
});

it('nao transforma a linha de continuacao em transacao', function (): void {
    $text = <<<'TEXT'
    Vencimento: 09/07/2026
                     04/09    DOMO SEVEROBOG 10/10                             129,01
                              outros CARIACICA
    TEXT;

    $lines = (new ItauInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->description)->toBe('DOMO SEVEROBOG')
        ->and($lines[0]->amountCents)->toBe(12_901);
});
