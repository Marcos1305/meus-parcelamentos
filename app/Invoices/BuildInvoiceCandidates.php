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
     * @return array<int, InvoiceCandidate>
     */
    public function handle(ParsedInvoice $invoice, Card $card): array
    {
        $candidates = [];
        $seen = [];

        foreach (array_values($invoice->lines) as $line) {
            $candidate = $this->reconcileInvoiceLine->handle(
                $line,
                $invoice->dueDate,
                $card->closing_day,
                $card->due_day,
            );

            $fingerprint = $this->fingerprint($candidate);

            $isDuplicate = isset($seen[$fingerprint])
                || $this->purchaseAlreadyExists->handle(
                    $card,
                    $candidate->totalAmountCents,
                    $candidate->installmentsCount,
                    $candidate->purchasedAt,
                );

            $seen[$fingerprint] = true;

            $candidates[] = $isDuplicate ? $this->asDuplicate($candidate) : $candidate;
        }

        return $candidates;
    }

    /**
     * Mesma assinatura de PurchaseAlreadyExists, sem o cartao: dentro de uma
     * fatura o cartao e sempre o mesmo. Duas linhas que colidem aqui
     * colapsariam numa unica compra na gravacao, entao a segunda precisa
     * chegar a revisao marcada.
     */
    private function fingerprint(InvoiceCandidate $candidate): string
    {
        return implode('|', [
            $candidate->totalAmountCents,
            $candidate->installmentsCount,
            $candidate->purchasedAt->toDateString(),
        ]);
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
            status: CandidateStatus::Duplicate,
        );
    }
}
