<?php

namespace App\Actions;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class GenerateInstallments
{
    public function __construct(
        private BuildInstallmentSchedule $buildInstallmentSchedule,
    ) {}

    /**
     * Regrava as parcelas de uma compra a partir do cronograma atual.
     */
    public function handle(Purchase $purchase): void
    {
        $card = $purchase->card;

        $schedule = $this->buildInstallmentSchedule->handle(
            $purchase->total_amount_cents,
            $purchase->installments_count,
            $purchase->purchased_at,
            $card->closing_day,
            $card->due_day,
        );

        DB::transaction(function () use ($purchase, $schedule): void {
            $purchase->installments()->delete();
            $purchase->installments()->createMany($schedule);
        });
    }
}
