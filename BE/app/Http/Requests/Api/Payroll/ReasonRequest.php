<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/** Cancel, return-to-draft and create-adjustment: a reason is mandatory. Permissions are re-checked by the services. */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('payroll.manage', 'payroll.approve');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pay_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
