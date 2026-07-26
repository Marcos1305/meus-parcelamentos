<?php

namespace App\Http\Requests;

use App\Enums\CardBrand;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['required', Rule::enum(CardBrand::class)],
            'label' => ['nullable', 'string', 'max:40'],
            'last_four_digits' => ['required', 'digits:4'],
            'credit_limit_cents' => ['required', 'integer', 'min:1'],
            'closing_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
        ];
    }
}
