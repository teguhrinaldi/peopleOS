<?php

namespace App\Http\Requests\Admin;

use App\Models\WorkLocation;

class WorkLocationRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['branch_id', 'attendance_policy_id', 'code', 'name', 'address', 'latitude', 'longitude', 'radius_meters'], upper: ['code']);
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var WorkLocation|null $current */
        $current = $this->route('location');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('work_locations', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', $this->existsInCompany('branches')],
            'attendance_policy_id' => ['nullable', 'integer', $this->existsInCompany('attendance_policies')],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:10,5000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another location.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'radius_meters.between' => 'The radius must be between 10 and 5,000 metres.',
        ];
    }
}
