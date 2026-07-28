<?php

namespace App\Http\Requests;

use App\Enums\BudgetLineKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetLineRequest extends FormRequest
{
    /**
     * Só despesa é criada por aqui. A renda é uma linha única, mantida pelo
     * servidor, e a tela não oferece jeito de criar outra: aceitar `income`
     * deixaria duas linhas disputando a mesma linha ENTRADA do rodapé, e a
     * planilha mostraria só uma delas sem avisar que a outra existe.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([BudgetLineKind::Expense->value])],
            'name' => ['required', 'string', 'max:60'],
            'default_amount_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
