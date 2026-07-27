<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetLineRequest extends FormRequest
{
    /**
     * O tipo nao muda depois de criado: virar uma despesa em entrada mudaria
     * o sinal de todos os meses ja preenchidos sem que o usuario perceba.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'default_amount_cents' => ['required', 'integer', 'min:0'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
