<?php

namespace App\Http\Requests\Admin;

use App\Models\Holiday;
use App\Services\Admin\HolidayService;
use Illuminate\Validation\Rule;

class HolidayRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['date', 'name', 'type']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Holiday|null $current */
        $current = $this->route('holiday');

        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->where('company_id', $this->companyId())->ignore($current?->id)],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(HolidayService::TYPES))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date.unique' => 'There is already a holiday on this date.'];
    }
}
