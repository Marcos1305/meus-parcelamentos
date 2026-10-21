<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmInvoiceImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $total = count($this->candidatesInSession());

        return [
            // 'present' em vez de 'required': uma fatura sem nenhuma
            // parcelada, so com compras a vista, submete um lote vazio de
            // proposito.
            'lines' => ['present', 'array'],
            'lines.*.index' => ['required', 'integer', 'min:0', 'distinct', 'lt:'.max($total, 1)],
            'lines.*.purchased_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * O caminho manual (StorePurchaseRequest) recusa descricao longa demais,
     * total zerado e quantidade de parcelas fora da faixa. O candidato vem de
     * um parser e da sessao, entao passa pelas mesmas invariantes antes de
     * virar Purchase.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $candidates = $this->candidatesInSession();

                foreach ($this->lineIndexes() as $position => $index) {
                    $candidate = $candidates[$index] ?? null;

                    if (! is_array($candidate)) {
                        continue;
                    }

                    $violation = $this->violationIn($candidate);

                    if ($violation !== null) {
                        $validator->errors()->add("lines.{$position}.index", $violation);
                    }
                }
            },
        ];
    }

    /**
     * A sessao dura duas horas e sobrevive a deploy: a forma guardada pode
     * ser de uma versao anterior do app.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidatesInSession(): array
    {
        $import = $this->session()->get('invoice_import');

        if (! is_array($import) || ! is_array($import['candidates'] ?? null)) {
            return [];
        }

        return array_values(array_filter($import['candidates'], 'is_array'));
    }

    /**
     * @return array<int, int>
     */
    private function lineIndexes(): array
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return [];
        }

        $indexes = [];

        foreach (array_values($lines) as $position => $line) {
            if (is_array($line) && is_numeric($line['index'] ?? null)) {
                $indexes[$position] = (int) $line['index'];
            }
        }

        return $indexes;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function violationIn(array $candidate): ?string
    {
        $description = is_string($candidate['description'] ?? null) ? $candidate['description'] : '';
        $merchant = is_string($candidate['merchant'] ?? null) ? $candidate['merchant'] : '';
        $installmentsCount = (int) ($candidate['installments_count'] ?? 0);
        $totalAmountCents = (int) ($candidate['total_amount_cents'] ?? 0);

        if (trim($description) === '') {
            return 'Esta compra chegou sem descrição e não pode ser importada.';
        }

        if (mb_strlen($description) > Purchase::MAX_DESCRIPTION_LENGTH
            || mb_strlen($merchant) > Purchase::MAX_DESCRIPTION_LENGTH) {
            return 'A descrição desta compra passa de '.Purchase::MAX_DESCRIPTION_LENGTH.' caracteres.';
        }

        if ($installmentsCount < 1 || $installmentsCount > Purchase::MAX_INSTALLMENTS_COUNT) {
            return 'O número de parcelas desta compra precisa estar entre 1 e '.Purchase::MAX_INSTALLMENTS_COUNT.'.';
        }

        if ($totalAmountCents < 1) {
            return 'O valor total desta compra precisa ser maior que zero.';
        }

        return null;
    }
}
