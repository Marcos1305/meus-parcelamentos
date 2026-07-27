<?php

namespace App\Invoices;

interface ExtractsInvoiceText
{
    /**
     * Extrai o texto de um PDF de fatura.
     *
     * @throws \RuntimeException quando o PDF nao pode ser lido.
     */
    public function extract(string $path, ?string $password = null): string;
}
