<?php

namespace App\Invoices;

use App\Models\Card;

class BuildInvoiceCandidates
{
    public function __construct(
        private ReconcileInvoiceLine $reconcileInvoiceLine,
        private PurchaseAlreadyExists $purchaseAlreadyExists,
    ) {}

    /**
     * Duas linhas identicas na mesma fatura sao duas compras reais — uma
     * assinatura cobrada duas vezes no mesmo dia, no mesmo cartao virtual. A
     * deteccao de duplicata olha so para o que ja esta persistido, para que a
     * repeticao dentro do lote continue importavel.
     *
     * @return array<int, InvoiceCandidate>
     */
    public function handle(ParsedInvoice $invoice, Card $card): array
    {
        $candidates = [];

        foreach (array_values($invoice->lines) as $line) {
            $candidate = $this->reconcileInvoiceLine->handle(
                $line,
                $invoice->dueDate,
                $card->closing_day,
                $card->due_day,
            );

            $isDuplicate = $this->purchaseAlreadyExists->handle(
                $card,
                $candidate->totalAmountCents,
                $candidate->installmentsCount,
                $candidate->firstDueDate,
            );

            $candidates[] = $isDuplicate ? $this->asDuplicate($candidate) : $candidate;
        }

        return $candidates;
    }

    /**
     * A mesma compra aparece em faturas consecutivas como 3/10 e 4/10, mas
     * ambas resolvem para a mesma assinatura. A descricao fica de fora: o
     * texto do estabelecimento varia entre faturas.
     */
    private function asDuplicate(InvoiceCandidate $candidate): InvoiceCandidate
    {
        return new InvoiceCandidate(
            description: $candidate->description,
            merchant: $candidate->merchant,
            totalAmountCents: $candidate->totalAmountCents,
            installmentsCount: $candidate->installmentsCount,
            installmentNumber: $candidate->installmentNumber,
            installmentAmountCents: $candidate->installmentAmountCents,
            purchasedAt: $candidate->purchasedAt,
            firstDueDate: $candidate->firstDueDate,
            status: CandidateStatus::Duplicate,
        );
    }
}
