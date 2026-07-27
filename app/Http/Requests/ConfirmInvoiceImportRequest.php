<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmInvoiceImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $import = $this->session()->get('invoice_import');
        $total = is_array($import) ? count($import['candidates']) : 0;

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.index' => ['required', 'integer', 'min:0', 'distinct', 'lt:'.max($total, 1)],
            'lines.*.purchased_at' => ['required', 'date'],
        ];
    }
}
