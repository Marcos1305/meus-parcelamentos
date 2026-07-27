<?php

namespace App\Invoices;

readonly class ParsedInvoiceLine
{
    public function __construct(
        public string $description,
        public ?string $merchant,
        public int $transactionDay,
        public int $installmentNumber,
        public int $installmentsCount,
        public int $amountCents,
    ) {}
}
