<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    protected $fillable = [
        'employee_id', 'branch_id', 'department_id', 'position_id', 'manager_id', 'work_location_id',
        'shift_id', 'effective_from', 'effective_to', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id')->withTrashed();
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }
}
