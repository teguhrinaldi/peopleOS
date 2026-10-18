<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('currentAssignment') ? $this->currentAssignment : null;
        $viewer = $request->user();
        // Personal data is for the person themself and HR-level viewers, not for every manager up the chain.
        $personal = $viewer && ($viewer->employee?->id === $this->id || $viewer->hasPermission('employee.view'));

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'gender' => $personal ? $this->gender?->value : null,
            'date_of_birth' => $personal ? Fmt::date($this->date_of_birth) : null,
            'phone' => $personal ? $this->phone : null,
            'email' => $this->email,
            'address' => $personal ? $this->address : null,
            'join_date' => Fmt::date($this->join_date),
            'employment_type' => $this->employment_type?->value,
            'status' => $this->status->value,
            'terminated_at' => Fmt::date($this->terminated_at),
            'department' => $assignment?->department ? ['id' => $assignment->department->id, 'name' => $assignment->department->name] : null,
            'position' => $assignment?->position ? ['id' => $assignment->position->id, 'name' => $assignment->position->name] : null,
            'branch' => $assignment?->branch ? ['id' => $assignment->branch->id, 'name' => $assignment->branch->name] : null,
            'manager' => $assignment?->manager ? ['id' => $assignment->manager->id, 'full_name' => $assignment->manager->full_name] : null,
            'work_location' => $assignment?->workLocation ? ['id' => $assignment->workLocation->id, 'name' => $assignment->workLocation->name] : null,
            'shift' => $assignment?->shift ? [
                'id' => $assignment->shift->id,
                'name' => $assignment->shift->name,
                'start_time' => Fmt::hm($assignment->shift->start_time),
                'end_time' => Fmt::hm($assignment->shift->end_time),
            ] : null,
        ];
    }
}
