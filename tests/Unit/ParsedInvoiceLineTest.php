<?php

use App\Invoices\ParsedInvoiceLine;
use App\Invoices\UnreadableInvoiceException;

function lineWith(
    int $transactionDay = 2,
    int $installmentNumber = 3,
    int $installmentsCount = 10,
    int $amountCents = 12_990,
): ParsedInvoiceLine {
    return new ParsedInvoiceLine(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        transactionDay: $transactionDay,
        installmentNumber: $installmentNumber,
        installmentsCount: $installmentsCount,
        amountCents: $amountCents,
    );
}

it('aceita uma linha dentro das faixas validas', function (): void {
    expect(lineWith()->installmentsCount)->toBe(10);
});

it('recusa fatura sem quantidade de parcelas', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(installmentNumber: 1, installmentsCount: 0))
        ->toThrow(
            UnreadableInvoiceException::class,
            'Não foi possível ler a quantidade de parcelas de uma das compras desta fatura.',
        );
});

it('recusa quantidade de parcelas negativa', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(installmentNumber: 1, installmentsCount: -3))
        ->toThrow(
            UnreadableInvoiceException::class,
            'Não foi possível ler a quantidade de parcelas de uma das compras desta fatura.',
        );
});

it('recusa numero de parcela maior que a quantidade', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(installmentNumber: 13, installmentsCount: 10))
        ->toThrow(UnreadableInvoiceException::class);
});

it('recusa numero de parcela zerado', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(installmentNumber: 0))
        ->toThrow(UnreadableInvoiceException::class);
});

it('recusa valor de parcela zerado', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(amountCents: 0))
        ->toThrow(UnreadableInvoiceException::class);
});

it('recusa valor de parcela negativo', function (): void {
    expect(fn (): ParsedInvoiceLine => lineWith(amountCents: -100))
        ->toThrow(UnreadableInvoiceException::class);
});

it('recusa dia de transacao fora do mes', function (int $day): void {
    expect(fn (): ParsedInvoiceLine => lineWith(transactionDay: $day))
        ->toThrow(UnreadableInvoiceException::class);
})->with([0, -1, 32, 99]);
