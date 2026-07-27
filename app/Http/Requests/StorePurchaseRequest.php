<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'card_id' => [
                'required',
                Rule::exists('cards', 'id')->where('user_id', $this->user()->id),
            ],
            'description' => ['required', 'string', 'max:'.Purchase::MAX_DESCRIPTION_LENGTH],
            'merchant' => ['nullable', 'string', 'max:'.Purchase::MAX_DESCRIPTION_LENGTH],
            'total_amount_cents' => ['required', 'integer', 'min:1'],
            'installments_count' => ['required', 'integer', 'between:1,'.Purchase::MAX_INSTALLMENTS_COUNT],
            'purchased_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
