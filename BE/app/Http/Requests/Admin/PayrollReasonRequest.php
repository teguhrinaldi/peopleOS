<?php

namespace App\Http\Requests\Admin;

/**
 * Inputs of the workflow actions that need words: return to draft / cancel (reason), mark paid (reference),
 * and creating an adjustment period (reason, optional name and pay date).
 */
class PayrollReasonRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('payroll.manage', 'payroll.approve', 'payroll.finalize');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['reason', 'payment_reference', 'name', 'pay_date']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => [$this->routeIs('admin.payroll.return-to-draft', 'admin.payroll.cancel', 'admin.payroll.adjustments.store') ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
