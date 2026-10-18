<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'employee_number', 'full_name', 'photo_path', 'gender', 'date_of_birth',
        'phone', 'email', 'address', 'join_date', 'employment_type', 'status', 'terminated_at',
        'termination_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'terminated_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class)->orderByDesc('effective_from');
    }

    /** The assignment effective today (company timezone). Past attendance never uses this. */
    public function currentAssignment(): HasOne
    {
        $today = app(CompanyContext::class)->today()->toDateString();

        return $this->hasOne(EmployeeAssignment::class)->ofMany(
            ['effective_from' => 'max', 'id' => 'max'],
            fn (Builder $q) => $q->where('effective_from', '<=', $today)
                ->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today)),
        );
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeStatus::Active;
    }

    public function isTerminated(): bool
    {
        return $this->status === EmployeeStatus::Terminated;
    }

    /** Assignment that was effective on a given local date (used to build attendance snapshots). */
    public function assignmentOn(string $date): ?EmployeeAssignment
    {
        return $this->assignments()->reorder()
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    public function scopeStatus(Builder $query, EmployeeStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof EmployeeStatus ? $status->value : $status);
    }

    /** Employees whose *current* assignment reports to the given manager employee. */
    public function scopeDirectReportsOf(Builder $query, int $managerEmployeeId): Builder
    {
        $today = app(CompanyContext::class)->today()->toDateString();

        return $query->whereIn('employees.id', function ($q) use ($managerEmployeeId, $today) {
            $q->select('employee_id')->from('employee_assignments')
                ->where('manager_id', $managerEmployeeId)
                ->where('effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
        });
    }
}
