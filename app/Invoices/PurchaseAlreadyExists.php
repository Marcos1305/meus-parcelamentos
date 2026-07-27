<?php

namespace App\Invoices;

use App\Models\Card;
use App\Models\Purchase;
use Carbon\CarbonInterface;

class PurchaseAlreadyExists
{
    /**
     * Fingerprint unico de deteccao de duplicata, compartilhado entre a
     * revisao (BuildInvoiceCandidates, que testa contra a data inferida) e a
     * gravacao final (InvoiceImportController::confirm, que testa contra a
     * data efetivamente persistida) para que as duas nunca divirjam sobre o
     * que conta como a mesma compra.
     */
    public function handle(
        Card $card,
        int $totalAmountCents,
        int $installmentsCount,
        CarbonInterface $purchasedAt,
    ): bool {
        return Purchase::query()
            ->where('card_id', $card->id)
            ->where('total_amount_cents', $totalAmountCents)
            ->where('installments_count', $installmentsCount)
            ->whereDate('purchased_at', $purchasedAt->toDateString())
            ->exists();
    }
}
