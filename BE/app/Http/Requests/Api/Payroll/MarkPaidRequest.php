<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class MarkPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.finalize');
    }

    public function rules(): array
    {
        return ['payment_reference' => ['nullable', 'string', 'max:120']];
    }
}
