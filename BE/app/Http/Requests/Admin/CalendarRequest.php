<?php

namespace App\Http\Requests\Admin;

class CalendarRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['name', 'description']);
        // The grid posts days[1]=1 ... days[7]=0; keep the ISO numbers of ticked days.
        $this->merge([
            'is_default' => $this->boolean('is_default'),
            'days' => collect((array) $this->input('days', []))->filter(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))->keys()->map(fn ($k) => (int) $k)->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'days' => ['array'],
            'days.*' => ['integer', 'between:1,7'],
        ];
    }
}
