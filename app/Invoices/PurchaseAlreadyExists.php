<?php

namespace App\Invoices;

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class PurchaseAlreadyExists
{
    /**
     * Fingerprint unico de deteccao de duplicata, compartilhado entre a
     * revisao (BuildInvoiceCandidates, que testa contra o cronograma inferido)
     * e a gravacao final (InvoiceImportController::confirm, que testa contra o
     * cronograma que sera gravado) para que as duas nunca divirjam sobre o que
     * conta como a mesma compra.
     *
     * A ancora e o vencimento da primeira parcela, nao a data da compra. O
     * Nubank carimba a parcela 1 com a data real da compra e as parcelas 2 em
     * diante com a data de abertura do ciclo, entao a mesma compra resolve
     * para datas de compra diferentes conforme a fatura em que aparece. O
     * vencimento da primeira parcela nao muda: ele sai do mes de vencimento da
     * fatura recuado pelo numero da parcela, conta que da o mesmo mes em
     * qualquer fatura em que a compra apareca, e o dia vem do vencimento do
     * cartao. Por nao depender do dia de fechamento, ele ainda reconhece a
     * compra de quem cadastrou esse dia errado.
     */
    public function handle(
        Card $card,
        int $totalAmountCents,
        int $installmentsCount,
        CarbonInterface $firstDueDate,
    ): bool {
        return Purchase::query()
            ->where('card_id', $card->id)
            ->where('total_amount_cents', $totalAmountCents)
            ->where('installments_count', $installmentsCount)
            ->whereHas(
                'installments',
                /** @param Builder<Installment> $installments */
                function (Builder $installments) use ($firstDueDate): void {
                    $installments
                        ->where('number', 1)
                        ->whereDate('due_date', $firstDueDate->toDateString());
                },
            )
            ->exists();
    }
}
