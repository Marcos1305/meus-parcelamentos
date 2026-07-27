<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class ParsedInvoice
{
    /**
     * @param  array<int, ParsedInvoiceLine>  $lines
     */
    public function __construct(
        public string $issuer,
        public CarbonImmutable $dueDate,
        public ?string $lastFourDigits,
        public array $lines,
    ) {}
}
