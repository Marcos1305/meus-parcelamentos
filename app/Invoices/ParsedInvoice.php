<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class ParsedInvoice
{
    /**
     * Duas listas separadas em vez de uma lista com tipo misto: a linha
     * parcelada vira compromisso futuro e passa por revisao, a linha a vista
     * vira fato passado e e gravada direto. Sao dois destinos, e juntar os
     * dois num array so obrigaria todo consumidor a filtrar antes de usar.
     *
     * @param  array<int, ParsedInvoiceLine>  $lines
     * @param  array<int, ParsedCashLine>  $cashLines
     */
    public function __construct(
        public string $issuer,
        public CarbonImmutable $dueDate,
        public ?string $lastFourDigits,
        public array $lines,
        public array $cashLines = [],
    ) {}
}
