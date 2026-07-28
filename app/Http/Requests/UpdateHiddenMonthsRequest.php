<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHiddenMonthsRequest extends FormRequest
{
    /**
     * A lista chega inteira e substitui a anterior daquele ano, então `present`
     * é o que separa "não escondo mês nenhum" (lista vazia) de uma requisição
     * malformada que apagaria a escolha sem querer.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'months' => ['present', 'array', 'max:12'],
            'months.*' => [
                'string',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                $this->belongsToTheYearInTheUrl(),
            ],
        ];
    }

    /**
     * A rota já diz de que ano é a lista. Aceitar um mês de outro ano deixaria
     * a tela escondendo colunas que ela nem mostra.
     */
    private function belongsToTheYearInTheUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! str_starts_with($value, $this->route('year').'-')) {
                $fail('O mês não pertence ao ano informado.');
            }
        };
    }
}
