<?php

namespace App\Http\Requests\Admin;

use App\Enums\SalaryComponentKind;
use Illuminate\Validation\Rule;

/** One manual entry of a DRAFT adjustment period. The employee id is re-resolved inside the company. */
class PayrollEntryRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['employee_id', 'kind', 'label', 'amount', 'reason'], upper: ['kind']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->existsInCompany('employees')],
            'kind' => ['required', Rule::enum(SalaryComponentKind::class)],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['employee_id' => 'employee'];
    }
}
