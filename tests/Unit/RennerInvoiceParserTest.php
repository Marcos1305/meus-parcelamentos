<?php

use App\Invoices\Parsers\RennerInvoiceParser;
use App\Invoices\UnreadableInvoiceException;

function rennerFixture(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/faturas/renner.txt');
}

it('reconhece a fatura do renner', function (): void {
    expect((new RennerInvoiceParser)->supports(rennerFixture()))->toBeTrue();
});

it('nao reconhece fatura de outro emissor', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    expect((new RennerInvoiceParser)->supports($text))->toBeFalse();
})->with(['itau.txt', 'nubank.txt', 'mercado-pago.txt']);

it('nao reconhece a fatura do itau, que cita Renner como estabelecimento', function (): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/itau.txt');

    expect((new RennerInvoiceParser)->supports($text))->toBeFalse();
});

it('extrai o vencimento e o emissor', function (): void {
    $invoice = (new RennerInvoiceParser)->parse(rennerFixture());

    expect($invoice->dueDate->toDateString())->toBe('2026-05-10')
        ->and($invoice->issuer)->toBe('Renner');
});

it('extrai os quatro ultimos digitos do cartao', function (): void {
    expect((new RennerInvoiceParser)->parse(rennerFixture())->lastFourDigits)->toBe('7001');
});

it('extrai apenas as linhas parceladas', function (): void {
    $lines = (new RennerInvoiceParser)->parse(rennerFixture())->lines;

    expect($lines)->toHaveCount(15);

    foreach ($lines as $line) {
        expect($line->installmentsCount)->toBeGreaterThan(0)
            ->and($line->installmentNumber)->toBeLessThanOrEqual($line->installmentsCount)
            ->and($line->amountCents)->toBeGreaterThan(0);
    }
});

it('extrai os campos de uma linha parcelada', function (): void {
    $lines = (new RennerInvoiceParser)->parse(rennerFixture())->lines;

    expect($lines[0]->installmentNumber)->toBe(7)
        ->and($lines[0]->installmentsCount)->toBe(9)
        ->and($lines[0]->amountCents)->toBe(5_400)
        ->and($lines[0]->transactionDay)->toBe(26)
        ->and($lines[0]->description)->toBe('PONUFARABORI RIFULUSIG')
        ->and($lines[0]->merchant)->toBeNull();
});

it('recusa texto sem vencimento', function (): void {
    expect(fn () => (new RennerInvoiceParser)->parse('Realize Sociedade sem data'))
        ->toThrow(UnreadableInvoiceException::class);
});

it('ignora a secao de proximas faturas', function (): void {
    $lines = (new RennerInvoiceParser)->parse(rennerFixture())->lines;

    expect($lines)->toHaveCount(15);

    foreach ($lines as $line) {
        $parcelamentoProjetado = $line->installmentNumber === 8 && $line->installmentsCount === 9
            && $line->transactionDay === 26 && $line->amountCents === 5_400;

        expect($parcelamentoProjetado)->toBeFalse();
    }
});

it('nunca transforma uma linha de Compra a Vista em transacao', function (): void {
    $lines = (new RennerInvoiceParser)->parse(rennerFixture())->lines;

    foreach ($lines as $line) {
        expect($line->description)->not->toContain('Vista');
    }
});

it('converte um valor na casa dos milhares corretamente', function (): void {
    $text = "Vencimento: 10/05/2026\n"
        ."13/02/2026    Compra Parcelada Parc 1/2            COMERCIO TESTE                              1.234,56\n";

    $lines = (new RennerInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amountCents)->toBe(123_456);
});

it('descarta uma linha com valor negativo', function (): void {
    $text = "Vencimento: 10/05/2026\n"
        ."13/02/2026    Compra Parcelada Parc 1/2            COMERCIO TESTE                              -38,41\n";

    $lines = (new RennerInvoiceParser)->parse($text)->lines;

    expect($lines)->toHaveCount(0);
});

it('extrai as compras a vista', function (): void {
    $cashLines = (new RennerInvoiceParser)->parse(rennerFixture())->cashLines;

    expect($cashLines)->toHaveCount(21);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->description)->not->toContain('Compra a Vista');
    }
});

it('ignora pagamento de fatura', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new RennerInvoiceParser)->parse(rennerFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('pagamento');
    }
});

it('nao le a vista das proximas faturas', function (): void {
    $text = rennerFixture()
        ."\n01/09/2026    Compra a Vista                       LOJA DA PROXIMA FATURA                                    99,00\n";

    expect((new RennerInvoiceParser)->parse($text)->cashLines)->toHaveCount(21);
});
