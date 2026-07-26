<?php

namespace App\Http\Controllers;

use App\Actions\BuildHistoryQuery;
use App\Models\Installment;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportHistoryController extends Controller
{
    public function __invoke(Request $request, BuildHistoryQuery $buildHistoryQuery): StreamedResponse
    {
        $query = $buildHistoryQuery
            ->handle($request->user(), $buildHistoryQuery->filters($request))
            ->orderBy('due_date')
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Não foi possível abrir o buffer de saída do CSV.');
            }

            // BOM: sem ele o Excel em pt-BR corrompe os acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'cartao', 'descricao', 'estabelecimento', 'valor_total_compra',
                'parcela', 'total_parcelas', 'valor_parcela', 'vencimento', 'status',
            ], ';', '"', '');

            foreach ($query->cursor() as $installment) {
                fputcsv($handle, $this->row($installment), ';', '"', '');
            }

            fclose($handle);
        }, 'historico-parcelas.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function row(Installment $installment): array
    {
        $purchase = $installment->purchase;
        $card = $purchase->card;

        return [
            "{$card->name} •••• {$card->last_four_digits}",
            $purchase->description,
            $purchase->merchant ?? '',
            $this->money($purchase->total_amount_cents),
            (string) $installment->number,
            (string) $purchase->installments_count,
            $this->money($installment->amount_cents),
            $installment->due_date->format('d/m/Y'),
            $installment->status->label(),
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }
}
