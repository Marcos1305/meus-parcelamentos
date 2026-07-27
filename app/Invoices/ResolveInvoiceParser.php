<?php

namespace App\Invoices;

class ResolveInvoiceParser
{
    /**
     * @param  array<int, InvoiceParser>  $parsers
     */
    public function __construct(private array $parsers) {}

    /**
     * @throws UnsupportedInvoiceException
     */
    public function handle(string $text): InvoiceParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($text)) {
                return $parser;
            }
        }

        throw new UnsupportedInvoiceException(
            'Emissor não reconhecido. Suportamos Nubank, Mercado Pago, Itaú e Renner.',
        );
    }
}
