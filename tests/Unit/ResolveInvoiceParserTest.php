<?php

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\Parsers\ItauInvoiceParser;
use App\Invoices\Parsers\MercadoPagoInvoiceParser;
use App\Invoices\Parsers\NubankInvoiceParser;
use App\Invoices\Parsers\RennerInvoiceParser;
use App\Invoices\ResolveInvoiceParser;
use App\Invoices\UnsupportedInvoiceException;
use Carbon\CarbonImmutable;

function fakeParser(string $needle): InvoiceParser
{
    return new class($needle) implements InvoiceParser
    {
        public function __construct(private string $needle) {}

        public function supports(string $text): bool
        {
            return str_contains($text, $this->needle);
        }

        public function parse(string $text): ParsedInvoice
        {
            return new ParsedInvoice($this->needle, CarbonImmutable::parse('2026-05-10'), '0000', []);
        }
    };
}

it('escolhe o primeiro parser que reconhece o texto', function (): void {
    $resolve = new ResolveInvoiceParser([fakeParser('itau'), fakeParser('nubank')]);

    expect($resolve->handle('fatura itau e nubank juntas')->parse('')->issuer)->toBe('itau');
});

it('recusa texto que nenhum parser reconhece', function (): void {
    $resolve = new ResolveInvoiceParser([fakeParser('nubank')]);

    expect(fn (): InvoiceParser => $resolve->handle('fatura do banco desconhecido'))
        ->toThrow(UnsupportedInvoiceException::class);
});

/**
 * As assinaturas dos quatro emissores precisam ser mutuamente exclusivas. A
 * fatura do Itaú cita "RENNER" como estabelecimento de uma compra: se o parser
 * da Renner se apoiasse nessa palavra, sequestraria a fatura do Itaú e
 * importaria contra o cartão errado, com dia de fechamento e vencimento
 * errados. Enquanto este teste passar, a ordem de registro dos parsers não
 * altera qual deles atende cada fatura.
 */
it('cada fatura e reconhecida por exatamente um parser', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    $suportam = array_filter(
        [new NubankInvoiceParser, new MercadoPagoInvoiceParser, new ItauInvoiceParser, new RennerInvoiceParser],
        fn (InvoiceParser $parser): bool => $parser->supports($text),
    );

    expect($suportam)->toHaveCount(1);
})->with(['nubank.txt', 'mercado-pago.txt', 'itau.txt', 'renner.txt']);
