<?php

namespace App\Services\Admin;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\Payslip;
use App\Models\Position;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Models\WorkingCalendarAssignment;
use App\Models\WorkLocation;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns audit rows into readable sentences. Subject names are batch-loaded (one query per entity type).
 */
class ActivityPresenter
{
    private const VERBS = [
        'auth.login' => ['signed in', 'log-in', 'muted'],
        'auth.logout' => ['signed out', 'log-out', 'muted'],
        'employee.created' => ['created employee', 'user-plus', 'success'],
        'employee.updated' => ['updated', 'pencil', 'primary'],
        'employee.reassigned' => ['changed the assignment of', 'git-branch', 'primary'],
        'employee.status_changed' => ['changed the status of', 'user-check', 'warning'],
        'employee.terminated' => ['terminated', 'user-x', 'danger'],
        'employee.deleted' => ['deleted employee', 'trash-2', 'danger'],
        'attendance.check_in' => ['checked in', 'log-in', 'success'],
        'attendance.check_out' => ['checked out', 'log-out', 'primary'],
        'attendance.review_approved' => ['approved the flagged attendance of', 'circle-check', 'success'],
        'attendance.review_rejected' => ['rejected the flagged attendance of', 'circle-alert', 'danger'],
        'attendance.exported' => ['exported attendance', 'download', 'muted'],
        'attendance_correction.requested' => ['requested an attendance correction for', 'clipboard-list', 'warning'],
        'attendance_correction.approved' => ['approved the attendance correction of', 'clipboard-check', 'success'],
        'attendance_correction.rejected' => ['rejected the attendance correction of', 'clipboard-list', 'danger'],
        'attendance_correction.cancelled' => ['cancelled the attendance correction of', 'clipboard-list', 'muted'],
        'overtime.requested' => ['requested overtime for', 'timer', 'warning'],
        'overtime.approved' => ['approved overtime for', 'timer', 'success'],
        'overtime.rejected' => ['rejected overtime for', 'timer', 'danger'],
        'overtime.cancelled' => ['cancelled overtime for', 'timer', 'muted'],
        'company.updated' => ['updated the company profile', 'building', 'primary'],
        'payroll_period.created' => ['created a payroll period', 'wallet', 'success'],
        'payroll_period.updated' => ['updated a payroll period', 'pencil', 'primary'],
        'payroll.generated' => ['generated payroll', 'refresh-cw', 'primary'],
        'payroll.regenerated' => ['regenerated payroll', 'refresh-cw', 'primary'],
        'payroll.submitted' => ['submitted payroll for review', 'send', 'warning'],
        'payroll.approved' => ['approved payroll', 'circle-check', 'success'],
        'payroll.returned' => ['returned payroll to draft', 'undo-2', 'warning'],
        'payroll.finalized' => ['finalized payroll', 'lock', 'success'],
        'payroll.paid' => ['marked payroll as paid', 'banknote', 'success'],
        'payroll.cancelled' => ['cancelled payroll', 'ban', 'danger'],
        'payroll_adjustment.period_created' => ['created an adjustment period', 'file-plus', 'primary'],
        'payroll_adjustment.entry_added' => ['added a payroll adjustment entry', 'plus', 'primary'],
        'payroll_adjustment.entry_removed' => ['removed a payroll adjustment entry', 'trash-2', 'warning'],
        'payslip.pdf_regenerated' => ['regenerated a payslip PDF', 'receipt', 'muted'],
        'salary_component.created' => ['added a salary component', 'plus', 'success'],
        'salary_component.updated' => ['updated a salary component', 'pencil', 'primary'],
        'salary_component.deleted' => ['deleted a salary component', 'trash-2', 'danger'],
        'salary_structure.created' => ['created a salary structure', 'layers', 'success'],
        'salary_structure.updated' => ['updated a salary structure', 'layers', 'primary'],
        'salary_structure.deleted' => ['deleted a salary structure', 'trash-2', 'danger'],
        'employee_salary.changed' => ['changed the salary of', 'wallet', 'primary'],
        'document.uploaded' => ['uploaded a document', 'upload', 'success'],
        'document.updated' => ['updated a document', 'pencil', 'primary'],
        'document.archived' => ['archived a document', 'archive', 'warning'],
        'document.restored' => ['restored a document', 'archive-restore', 'success'],
        'document.deleted' => ['deleted a document', 'trash-2', 'danger'],
        'document_type.created' => ['added a document type', 'plus', 'success'],
        'document_type.updated' => ['updated a document type', 'pencil', 'primary'],
        'report.exported' => ['exported a report', 'download', 'muted'],
    ];

    /** Entity types named by their `name` column. */
    private const NAMED = [
        Department::class, Position::class, Branch::class, Shift::class, WorkLocation::class,
        AttendancePolicy::class, WorkingCalendar::class, Holiday::class, Company::class, Role::class,
    ];

    /** Entity types named after the employee they belong to. */
    private const VIA_EMPLOYEE = [
        Attendance::class, AttendanceCorrection::class, OvertimeRequest::class, ShiftAssignment::class, WorkingCalendarAssignment::class,
    ];

    public function __construct(private readonly CompanyContext $context) {}

    /**
     * @param  Collection<int, Activity>  $activities  with `actor` eager loaded
     * @return Collection<int, array<string, mixed>>
     */
    public function present(Collection $activities): Collection
    {
        $names = $this->subjectNames($activities);

        return $activities->map(function (Activity $a) use ($names) {
            [$verb, $icon, $tone] = self::VERBS[$a->action] ?? $this->fallback($a->action);
            $subject = $names[$a->entity_type][$a->entity_id] ?? null;
            $self = in_array($a->action, ['auth.login', 'auth.logout', 'attendance.check_in', 'attendance.check_out', 'company.updated', 'attendance.exported'], true);

            return [
                'id' => $a->id,
                'actor' => $a->actor?->name ?? 'System',
                'verb' => $verb,
                'subject' => $self ? null : $subject,
                'subject_url' => $this->url($a),
                'icon' => $icon,
                'tone' => $tone,
                'at' => $this->context->toLocal($a->created_at),
                'changes' => $this->changes($a),
                'action' => $a->action,
                'entity' => self::entityLabel($a->entity_type).($a->entity_id ? " #{$a->entity_id}" : ''),
                'diff' => $this->diff($a),
                'ip' => $a->ip_address,
                'description' => $a->description,
            ];
        });
    }

    /** "App\Models\WorkLocation" -> "Work location"; plain strings (e.g. "attendance") pass through. */
    public static function entityLabel(string $type): string
    {
        return Str::of(class_basename($type))->snake(' ')->ucfirst()->toString();
    }

    /**
     * Every key of old/new side by side, for the detail drawer. Values were redacted when logged.
     *
     * @return list<array{key: string, old: string, new: string, changed: bool}>
     */
    private function diff(Activity $a): array
    {
        $old = $a->old_values ?? [];
        $new = $a->new_values ?? [];
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = array_key_exists($key, $old) ? $this->scalar($old[$key], 500) : '—';
            $after = array_key_exists($key, $new) ? $this->scalar($new[$key], 500) : '—';
            $rows[] = ['key' => Str::headline((string) $key), 'old' => $before, 'new' => $after, 'changed' => $before !== $after];
        }

        return $rows;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function fallback(string $action): array
    {
        [$entity, $verb] = array_pad(explode('.', $action, 2), 2, 'changed');
        $tone = match (true) {
            str_contains($verb, 'delet') => 'danger',
            str_contains($verb, 'creat') => 'success',
            default => 'primary',
        };
        $icon = str_contains($verb, 'delet') ? 'trash-2' : (str_contains($verb, 'creat') ? 'plus' : 'pencil');

        return [Str::of($verb)->replace('_', ' ')->append(' '.Str::of($entity)->replace('_', ' '))->toString(), $icon, $tone];
    }

    private function url(Activity $a): ?string
    {
        if (! $a->entity_id || str_ends_with($a->action, 'deleted')) {
            return null;
        }

        return match ($a->entity_type) {
            Employee::class => route('admin.employees.show', $a->entity_id),
            Attendance::class => route('admin.attendance.show', $a->entity_id),
            Document::class => route('admin.documents.show', $a->entity_id),
            Payslip::class => route('admin.payslips.show', $a->entity_id),
            default => null,
        };
    }

    /** @return list<string> human "field: old -> new" lines, secrets are already redacted by ActivityLogger */
    private function changes(Activity $a): array
    {
        if (! str_ends_with($a->action, '.updated') && ! in_array($a->action, ['employee.status_changed', 'employee.terminated'], true)) {
            return [];
        }

        $old = $a->old_values ?? [];
        $lines = [];
        foreach (($a->new_values ?? []) as $key => $value) {
            $label = Str::headline($key).': ';
            $before = $old[$key] ?? null;
            // Only draw an arrow when there is a distinct previous value to show.
            $lines[] = $before === null || $before === $value
                ? $label.$this->scalar($value)
                : $label.$this->scalar($before).' → '.$this->scalar($value);
        }

        return array_slice($lines, 0, 6);
    }

    private function scalar(mixed $value, int $limit = 60): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $limit),
            default => Str::limit((string) $value, $limit),
        };
    }

    /** @return array<string, array<int, string>> entity_type => [id => label] */
    private function subjectNames(Collection $activities): array
    {
        $out = [];
        foreach ($activities->groupBy('entity_type') as $type => $rows) {
            $ids = $rows->pluck('entity_id')->filter()->unique()->all();
            if ($ids === []) {
                continue;
            }

            $out[$type] = match (true) {
                $type === Employee::class => Employee::withTrashed()->whereIn('id', $ids)->get(['id', 'full_name', 'employee_number'])
                    ->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} ({$e->employee_number})"])->all(),
                $type === User::class => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                in_array($type, self::NAMED, true) => $this->named($type, $ids),
                in_array($type, self::VIA_EMPLOYEE, true) => $this->viaEmployee($type, $ids),
                default => [],
            };
        }

        return $out;
    }

    /** @param class-string $type @return array<int, string> */
    private function named(string $type, array $ids): array
    {
        $query = method_exists($type, 'bootSoftDeletes') ? $type::withTrashed() : $type::query();

        return $query->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @param class-string $type @return array<int, string> */
    private function viaEmployee(string $type, array $ids): array
    {
        $rows = $type::query()->whereIn('id', $ids)->pluck('employee_id', 'id');
        $names = Employee::withTrashed()->whereIn('id', $rows->unique()->all())->pluck('full_name', 'id');

        return $rows->map(fn ($employeeId) => $names[$employeeId] ?? null)->filter()->all();
    }
}
