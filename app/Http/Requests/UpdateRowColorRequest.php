<?php

namespace App\Http\Requests;

use App\Enums\LineColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRowColorRequest extends FormRequest
{
    /**
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
            'color' => ['present', 'nullable', Rule::enum(LineColor::class)],
        ];
    }
}
