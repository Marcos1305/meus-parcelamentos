<?php

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
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
    $resolve = new ResolveInvoiceParser([fakeParser('nubank'), fakeParser('itau')]);

    expect($resolve->handle('fatura itau de maio')->parse('')->issuer)->toBe('itau');
});

it('recusa texto que nenhum parser reconhece', function (): void {
    $resolve = new ResolveInvoiceParser([fakeParser('nubank')]);

    expect(fn (): InvoiceParser => $resolve->handle('fatura do banco desconhecido'))
        ->toThrow(UnsupportedInvoiceException::class);
});
