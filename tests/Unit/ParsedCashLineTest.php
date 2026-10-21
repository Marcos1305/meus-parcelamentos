<?php

use App\Invoices\ParsedCashLine;
use App\Invoices\UnreadableInvoiceException;

it('aceita uma linha bem formada', function (): void {
    $line = new ParsedCashLine(
        description: 'Padaria do Bairro',
        transactionDay: 5,
        transactionMonth: 6,
        amountCents: 1_990,
    );

    expect($line->description)->toBe('Padaria do Bairro')
        ->and($line->transactionDay)->toBe(5)
        ->and($line->transactionMonth)->toBe(6)
        ->and($line->amountCents)->toBe(1_990);
});

it('recusa valor que nao e positivo', function (int $amountCents): void {
    new ParsedCashLine('Padaria', 5, 6, $amountCents);
})->with([0, -100])->throws(UnreadableInvoiceException::class);

it('recusa dia fora do mes', function (int $day): void {
    new ParsedCashLine('Padaria', $day, 6, 1_990);
})->with([0, 32])->throws(UnreadableInvoiceException::class);

it('recusa mes fora do ano', function (int $month): void {
    new ParsedCashLine('Padaria', 5, $month, 1_990);
})->with([0, 13])->throws(UnreadableInvoiceException::class);
