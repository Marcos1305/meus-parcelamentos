<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMonthAmountRequest extends FormRequest
{
    /**
     * `present` e nao `required`: null e um valor legitimo — apaga o registro —
     * mas o campo ausente e requisicao malformada. Sem isso, um erro de
     * serializacao no cliente apagaria em silencio um valor digitado.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['present', 'nullable', 'integer', 'min:0'],
        ];
    }
}
