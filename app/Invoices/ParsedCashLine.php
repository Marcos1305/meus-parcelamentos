<?php

namespace App\Invoices;

readonly class ParsedCashLine
{
    /**
     * As invariantes ficam no construtor pelo mesmo motivo de
     * ParsedInvoiceLine: o app nao guarda o PDF, entao uma linha que chegasse
     * malformada aqui viraria um registro impossivel de reproduzir depois.
     *
     * Os extratores garantem que nada fora dessas faixas chega ate aqui — o
     * proprio regex recusa mes invalido, e valor zero ou negativo e descartado
     * antes. Estas guardas existem para o dia em que um regex for afrouxado.
     *
     * @throws UnreadableInvoiceException
     */
    public function __construct(
        public string $description,
        public int $transactionDay,
        public int $transactionMonth,
        public int $amountCents,
    ) {
        if ($amountCents < 1) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler o valor de uma das compras à vista desta fatura.',
            );
        }

        if ($transactionDay < 1 || $transactionDay > 31) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a data de uma das compras à vista desta fatura.',
            );
        }

        if ($transactionMonth < 1 || $transactionMonth > 12) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler o mês de uma das compras à vista desta fatura.',
            );
        }
    }
}
