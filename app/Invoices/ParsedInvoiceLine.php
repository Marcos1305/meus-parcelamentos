<?php

namespace App\Invoices;

readonly class ParsedInvoiceLine
{
    /**
     * As invariantes ficam no construtor porque a reconciliacao indexa o
     * cronograma pelo numero da parcela e divide pela quantidade: qualquer
     * parser que produzisse uma linha fora dessas faixas viraria erro fatal
     * num PDF que o app nao guarda e portanto nao consegue reproduzir.
     *
     * @throws UnreadableInvoiceException
     */
    public function __construct(
        public string $description,
        public ?string $merchant,
        public int $transactionDay,
        public int $installmentNumber,
        public int $installmentsCount,
        public int $amountCents,
    ) {
        if ($installmentsCount < 1) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a quantidade de parcelas de uma das compras desta fatura.',
            );
        }

        if ($installmentNumber < 1 || $installmentNumber > $installmentsCount) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a numeração das parcelas de uma das compras desta fatura.',
            );
        }

        if ($amountCents < 1) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler o valor da parcela de uma das compras desta fatura.',
            );
        }

        if ($transactionDay < 1 || $transactionDay > 31) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a data de uma das compras desta fatura.',
            );
        }
    }
}
