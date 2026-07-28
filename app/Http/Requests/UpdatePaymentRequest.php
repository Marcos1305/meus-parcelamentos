<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    /**
     * `present` pelo mesmo motivo das outras células: o campo ausente é
     * requisição malformada, não um desmarcar acidental.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'paid' => ['present', 'boolean'],
        ];
    }
}
