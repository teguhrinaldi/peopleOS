<?php

namespace App\Http\Requests\Admin;

use App\Support\Currencies;
use App\Support\DateDisplay;
use Illuminate\Validation\Rule;

class GeneralSettingsRequest extends AdminRequest
{
    /** form field => App\Support\Settings key */
    public const SETTING_FIELDS = [
        'currency' => 'general.currency',
        'date_format' => 'general.date_format',
        'employee_number_prefix' => 'employee.number_prefix',
        'employee_number_digits' => 'employee.number_digits',
        'leave_year_start_month' => 'leave.year_start_month',
        'payroll_require_separate_approver' => 'payroll.require_separate_approver',
        'payslip_prefix' => 'payroll.payslip_prefix',
        'payroll_rounding_decimals' => 'payroll.rounding_decimals',
        'documents_expiring_days' => 'documents.expiring_days',
    ];

    public function authorize(): bool
    {
        return $this->user()->hasPermission('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['name', 'timezone', 'address', 'currency', 'employee_number_prefix', 'payslip_prefix'], upper: ['currency', 'employee_number_prefix', 'payslip_prefix']);
        // The toggle always posts 0/1 from the form; an omitted field means "leave it alone", not "turn it off".
        if ($this->has('payroll_require_separate_approver')) {
            $this->merge(['payroll_require_separate_approver' => $this->boolean('payroll_require_separate_approver')]);
        }
        // An empty prefix is allowed (plain numbers), so keep it as '' rather than null.
        if ($this->has('employee_number_prefix') && $this->input('employee_number_prefix') === null) {
            $this->merge(['employee_number_prefix' => '']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'address' => ['nullable', 'string', 'max:1000'],
            // Raster only: an SVG logo could carry script.
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['nullable', 'boolean'],

            'currency' => ['sometimes', 'required', 'string', 'size:3', function ($attribute, $value, $fail) {
                if (! Currencies::isValid((string) $value)) {
                    $fail('Choose a valid ISO 4217 currency code, e.g. IDR or USD.');
                }
            }],
            'date_format' => ['sometimes', 'required', Rule::in(array_keys(DateDisplay::FORMATS))],
            'employee_number_prefix' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Z0-9._\/-]*$/'],
            'employee_number_digits' => ['sometimes', 'required', 'integer', 'between:1,10'],
            'leave_year_start_month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'payroll_require_separate_approver' => ['sometimes', 'boolean'],
            'payslip_prefix' => ['sometimes', 'required', 'string', 'max:10', 'regex:/^[A-Z0-9._\/-]+$/'],
            'payroll_rounding_decimals' => ['sometimes', 'required', Rule::in(['auto', '0', '1', '2'])],
            'documents_expiring_days' => ['sometimes', 'required', 'integer', 'between:1,365'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone' => 'Choose a valid time zone from the list.',
            'logo.max' => 'The logo must be 1 MB or smaller.',
            'logo.mimes' => 'Use a PNG, JPG or WebP image.',
            'employee_number_prefix.regex' => 'Use capital letters, digits and . _ / - only.',
            'payslip_prefix.regex' => 'Use capital letters, digits and . _ / - only.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return parent::attributes() + [
            'employee_number_prefix' => 'employee number prefix', 'employee_number_digits' => 'number of digits',
            'leave_year_start_month' => 'leave year start', 'payslip_prefix' => 'payslip prefix',
            'payroll_rounding_decimals' => 'rounding', 'documents_expiring_days' => 'expiry warning days', 'date_format' => 'date format',
        ];
    }

    /** Company row fields. @return array<string, mixed> */
    public function settings(): array
    {
        return $this->safe()->only(['name', 'timezone', 'address']);
    }

    /** Typed settings keyed by their App\Support\Settings key. @return array<string, mixed> */
    public function typedSettings(): array
    {
        $out = [];
        foreach (self::SETTING_FIELDS as $field => $key) {
            if ($this->has($field)) {
                $out[$key] = $this->validated($field) ?? '';
            }
        }

        return $out;
    }
}
