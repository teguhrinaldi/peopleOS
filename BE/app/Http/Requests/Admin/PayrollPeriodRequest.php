<?php

namespace App\Http\Requests\Admin;

/** Create a regular period (dates) or edit a not-yet-locked one (name, pay date, notes). */
class PayrollPeriodRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['start_date', 'end_date', 'name', 'pay_date', 'notes']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('post');

        return [
            'start_date' => [$create ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'end_date' => [$create ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'name' => ['nullable', 'string', 'max:120'],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['start_date' => 'start date', 'end_date' => 'end date', 'pay_date' => 'pay date'];
    }
}
