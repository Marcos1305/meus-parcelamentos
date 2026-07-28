<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRowColorRequest extends FormRequest
{
    /**
     * A cor é o hexadecimal em si, não um apelido de paleta: o seletor deixa
     * escolher qualquer tom. A paleta continua existindo como atalho na tela.
     *
     * `present` e não `required`: `null` é um valor legítimo — tira a cor da
     * linha — mas o campo ausente é requisição malformada. Mesma regra do valor
     * mensal, pelo mesmo motivo: um erro de serialização no cliente não pode
     * virar apagamento silencioso.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'color' => ['present', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
