<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    /**
     * `present` e nao `required`: null e valor legitimo — devolve o gasto para
     * o balde "sem categoria" e o proximo job o reclassifica —, mas campo
     * ausente e requisicao malformada. Mesma regra da cor da linha do
     * planejamento, pelo mesmo motivo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['present', 'nullable', Rule::enum(ExpenseCategory::class)],
        ];
    }
}
