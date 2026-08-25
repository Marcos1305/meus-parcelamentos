<?php

namespace App\Http\Requests;

use App\Concerns\NormalizesNotes;
use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseNotesRequest extends FormRequest
{
    use NormalizesNotes;

    protected function prepareForValidation(): void
    {
        $this->normalizeNotes();
    }

    /**
     * `present` em vez de `required`: apagar a nota e uma operacao legitima e
     * manda null, que `required` recusaria. O campo ainda precisa vir, para
     * que um corpo vazio nunca seja lido como "nao mexe" e o PATCH vire uma
     * escrita silenciosamente sem efeito.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['present', 'nullable', 'string', 'max:'.Purchase::MAX_NOTES_LENGTH],
        ];
    }
}
