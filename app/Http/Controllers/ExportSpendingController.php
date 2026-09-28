<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportSpendingRequest;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportSpendingController extends Controller
{
    /**
     * Filtra pela data da compra, nao pela data de cobranca que a tela de
     * gastos usa: o total de um periodo aqui nao fecha com o total do mesmo
     * mes la. Pelo mesmo motivo a compra parcelada sai numa linha so, com o
     * valor total — a compra aconteceu uma vez, nesse dia.
     */
    public function __invoke(ExportSpendingRequest $request): StreamedResponse
    {
        $from = $request->validated('de');
        $until = $request->validated('ate');
        $user = $request->user();

        $cash = CashPurchase::query()
            ->forUser($user)
            ->with('card:id,name,last_four_digits')
            ->whereDate('purchased_at', '>=', $from)
            ->whereDate('purchased_at', '<=', $until)
            ->get()
            ->map(fn (CashPurchase $purchase): array => $this->row(
                $purchase->purchased_at, $purchase->card, $purchase->description,
                $purchase->category?->label(), 'À vista', 1, $purchase->amount_cents,
            ));

        $purchases = Purchase::query()
            ->forUser($user)
            ->with('card:id,name,last_four_digits')
            ->whereDate('purchased_at', '>=', $from)
            ->whereDate('purchased_at', '<=', $until)
            ->get()
            ->map(fn (Purchase $purchase): array => $this->row(
                $purchase->purchased_at, $purchase->card, $purchase->description,
                $purchase->category?->label(), 'Parcelada', $purchase->installments_count, $purchase->total_amount_cents,
            ));

        $rows = $cash->concat($purchases)->sortBy('sort_key')->values();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Não foi possível abrir o buffer de saída do CSV.');
            }

            // BOM: sem ele o Excel em pt-BR corrompe os acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['data_compra', 'cartao', 'descricao', 'categoria', 'tipo', 'parcelas', 'valor'], ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, $row['columns'], ';', '"', '');
            }

            fclose($handle);
        }, "gastos-{$from}-a-{$until}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{sort_key: string, columns: array<int, string>}
     */
    private function row(CarbonImmutable $purchasedAt, Card $card, string $description, ?string $category, string $kind, int $installmentsCount, int $amountCents): array
    {
        return [
            'sort_key' => $purchasedAt->toDateString(),
            'columns' => [
                $purchasedAt->format('d/m/Y'),
                "{$card->name} •••• {$card->last_four_digits}",
                $description,
                $category ?? '',
                $kind,
                (string) $installmentsCount,
                number_format($amountCents / 100, 2, ',', ''),
            ],
        ];
    }
}
