<?php

namespace App\Invoices;

interface InvoiceParser
{
    /**
     * Reconhece o emissor pela assinatura no texto extraido.
     */
    public function supports(string $text): bool;

    /**
     * @throws UnreadableInvoiceException quando falta vencimento
     *                                    ou nao ha linha parcelada.
     */
    public function parse(string $text): ParsedInvoice;
}
