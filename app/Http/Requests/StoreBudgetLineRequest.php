<?php

namespace App\Http\Requests;

use App\Enums\BudgetLineKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetLineRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(BudgetLineKind::class)],
            'name' => ['required', 'string', 'max:60'],
            'default_amount_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
