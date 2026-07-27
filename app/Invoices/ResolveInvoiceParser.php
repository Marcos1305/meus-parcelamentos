<?php

namespace App\Invoices;

class ResolveInvoiceParser
{
    /**
     * @param  array<int, InvoiceParser>  $parsers
     */
    public function __construct(private array $parsers) {}

    /**
     * Sem nenhum parser registrado a importacao nao tem como funcionar, e a
     * interface precisa saber disso para nao se anunciar como pronta.
     */
    public function hasParsers(): bool
    {
        return $this->parsers !== [];
    }

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

        throw new UnsupportedInvoiceException($this->hasParsers()
            ? 'Emissor não reconhecido. Suportamos Nubank, Mercado Pago, Itaú e Renner.'
            : 'A importação de fatura ainda não está disponível.');
    }
}
