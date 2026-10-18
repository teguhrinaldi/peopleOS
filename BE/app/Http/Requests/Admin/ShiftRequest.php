<?php

namespace App\Http\Requests\Admin;

use App\Models\Shift;

/** Wall-clock inputs only. Duration, overnight flag and working minutes are derived server-side. */
class ShiftRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'start_time', 'end_time'], upper: ['code']);
        $breaks = collect($this->input('breaks', []))
            ->filter(fn ($b) => is_array($b) && trim(($b['name'] ?? '').($b['start_time'] ?? '').($b['end_time'] ?? '')) !== '')
            ->map(fn ($b) => ['name' => trim((string) ($b['name'] ?? '')), 'start_time' => $b['start_time'] ?? null, 'end_time' => $b['end_time'] ?? null, 'is_paid' => filter_var($b['is_paid'] ?? false, FILTER_VALIDATE_BOOLEAN)])
            ->values()->all();

        $this->merge(['is_active' => $this->boolean('is_active'), 'breaks' => $breaks]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Shift|null $current */
        $current = $this->route('shift');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('shifts', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'grace_late_minutes' => ['required', 'integer', 'between:0,240'],
            'grace_early_leave_minutes' => ['required', 'integer', 'between:0,240'],
            'is_active' => ['boolean'],
            'breaks' => ['array', 'max:6'],
            'breaks.*.name' => ['required', 'string', 'max:60'],
            'breaks.*.start_time' => ['required', 'date_format:H:i'],
            'breaks.*.end_time' => ['required', 'date_format:H:i', 'different:breaks.*.start_time'],
            'breaks.*.is_paid' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another shift.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
            'end_time.different' => 'The end time must differ from the start time.',
            'breaks.*.name.required' => 'Give the break a name.',
            'breaks.*.start_time.required' => 'Set when the break starts.',
            'breaks.*.end_time.required' => 'Set when the break ends.',
        ];
    }

    /** Only what the form may decide; derived numbers are added by ShiftSetupService. @return array<string, mixed> */
    public function shiftData(): array
    {
        return $this->safe()->only(['code', 'name', 'start_time', 'end_time', 'grace_late_minutes', 'grace_early_leave_minutes', 'is_active', 'breaks']);
    }
}
