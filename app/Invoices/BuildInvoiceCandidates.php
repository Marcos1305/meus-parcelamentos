<?php

namespace App\Invoices;

use App\Models\Card;
use App\Models\Purchase;

class BuildInvoiceCandidates
{
    public function __construct(
        private ReconcileInvoiceLine $reconcileInvoiceLine,
    ) {}

    /**
     * @return array<int, InvoiceCandidate>
     */
    public function handle(ParsedInvoice $invoice, Card $card): array
    {
        return array_map(
            fn (ParsedInvoiceLine $line): InvoiceCandidate => $this->markDuplicate(
                $this->reconcileInvoiceLine->handle(
                    $line,
                    $invoice->dueDate,
                    $card->closing_day,
                    $card->due_day,
                ),
                $card,
            ),
            array_values($invoice->lines),
        );
    }

    /**
     * A mesma compra aparece em faturas consecutivas como 3/10 e 4/10, mas
     * ambas resolvem para a mesma assinatura. A descricao fica de fora: o
     * texto do estabelecimento varia entre faturas.
     */
    private function markDuplicate(InvoiceCandidate $candidate, Card $card): InvoiceCandidate
    {
        $exists = Purchase::query()
            ->where('card_id', $card->id)
            ->where('total_amount_cents', $candidate->totalAmountCents)
            ->where('installments_count', $candidate->installmentsCount)
            ->whereDate('purchased_at', $candidate->purchasedAt->toDateString())
            ->exists();

        if (! $exists) {
            return $candidate;
        }

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
