<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invoice' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'password' => ['nullable', 'string', 'max:60'],
            'card_id' => [
                'nullable',
                Rule::exists('cards', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice.mimes' => 'O arquivo precisa ser um PDF.',
            'invoice.max' => 'O PDF pode ter no máximo 10 MB.',
            'card_id.exists' => 'Selecione um cartão válido.',
        ];
    }
}
