<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\CashPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WriteCashPurchases
{
    public function __construct(private ResolveCashPurchaseDate $resolveCashPurchaseDate) {}

    /**
     * A gravacao e em lote com insertOrIgnore porque a duplicata e resolvida
     * pelo indice unico da tabela, nao por consulta previa: reimportar a mesma
     * fatura precisa ser inofensivo mesmo quando duas importacoes acontecem ao
     * mesmo tempo.
     *
     * Como insertOrIgnore nao passa pelo modelo, os timestamps entram a mao.
     *
     * @param  array<int, array{description: string, day: int, month: int, amount_cents: int}>  $lines
     * @return int quantas foram efetivamente gravadas
     */
    public function handle(array $lines, Card $card, CarbonImmutable $invoiceDueDate): int
    {
        if ($lines === []) {
            return 0;
        }

        $now = CarbonImmutable::now();

        $rows = array_map(
            fn (array $line): array => [
                'card_id' => $card->id,
                'description' => $line['description'],
                'amount_cents' => $line['amount_cents'],
                'purchased_at' => $this->resolveCashPurchaseDate
                    ->handle($line['day'], $line['month'], $invoiceDueDate)
                    ->toDateString(),
                'billed_at' => $invoiceDueDate->toDateString(),
                'category' => null,
                'category_is_manual' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $lines,
        );

        return DB::table((new CashPurchase)->getTable())->insertOrIgnore($rows);
    }
}
