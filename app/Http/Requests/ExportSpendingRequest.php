<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExportSpendingRequest extends FormRequest
{
    /**
     * Ao contrario dos parametros da tela de gastos, que caem no default em
     * silencio, o periodo e preenchido pelo usuario num formulario: um erro
     * de validacao e a resposta certa, nao um arquivo de outro periodo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'de' => ['required', 'date_format:Y-m-d'],
            'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de'],
        ];
    }
}
