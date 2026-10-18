<?php

namespace App\Http\Requests\Admin;

use App\Enums\GeofenceMode;
use Illuminate\Validation\Rule;

class AttendancePolicyRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['name']);
        $this->merge(collect(['is_default', 'require_gps', 'require_selfie', 'reject_mock_location'])
            ->mapWithKeys(fn ($f) => [$f => $this->boolean($f)])->all());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'require_gps' => ['boolean'],
            'geofence_mode' => ['required', Rule::enum(GeofenceMode::class)],
            'require_selfie' => ['boolean'],
            'max_gps_accuracy_meters' => ['required', 'integer', 'between:5,1000'],
            'reject_mock_location' => ['boolean'],
            'offline_max_age_minutes' => ['required', 'integer', 'between:0,10080'],
        ];
    }
}
