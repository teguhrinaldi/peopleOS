<?php

namespace Database\Seeders;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\GeofenceMode;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Models\WorkLocation;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\ScheduleService;
use App\Services\Attendance\ShiftCalculator;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo company used by the Web Admin and by the Flutter app in API mode.
 * Every demo login uses the password "password". Never run this against production data.
 */
class DemoSeeder extends Seeder
{
    private Company $company;

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Position> */
    private array $positions = [];

    /** @var array<string, WorkLocation> */
    private array $locations = [];

    /** @var array<string, Shift> */
    private array $shifts = [];

    /** @var array<string, Employee> */
    private array $employees = [];

    public function run(): void
    {
        mt_srand(2026); // deterministic demo data

        DB::transaction(function () {
            $this->company();
            $this->calendarAndHolidays();
            $this->shifts();
            $this->organization();
            $this->employees();
        });

        app(CompanyContext::class)->forget();
        $this->attendanceHistory();
        $this->requests();
    }

    private function company(): void
    {
        $this->company = Company::query()->firstOrCreate(['name' => 'PeopleOS Demo Company'], [
            'legal_name' => 'PT PeopleOS Demo Indonesia',
            'email' => 'hello@peopleos.test',
            'phone' => '+62 22 1234 5678',
            'address' => 'Jl. Asia Afrika No. 65, Bandung, Jawa Barat',
            'timezone' => 'Asia/Jakarta',
        ]);

        foreach (['company.name' => $this->company->name, 'attendance.default_view' => 'today'] as $key => $value) {
            Setting::query()->updateOrCreate(['company_id' => $this->company->id, 'key' => $key], ['value' => $value]);
        }
    }

    private function calendarAndHolidays(): void
    {
        $calendar = WorkingCalendar::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Standard Week (Mon-Fri)'],
            ['description' => 'Monday to Friday, weekends off.', 'is_default' => true],
        );
        foreach (range(1, 7) as $day) {
            $calendar->days()->updateOrCreate(['day_of_week' => $day], ['is_working_day' => $day <= 5]);
        }

        // Illustrative Indonesian public holidays; adjust to your country/year in Settings.
        foreach ([
            '2026-01-01' => "New Year's Day", '2026-05-01' => 'Labour Day', '2026-06-01' => 'Pancasila Day',
            '2026-08-17' => 'Independence Day', '2026-12-25' => 'Christmas Day',
        ] as $date => $name) {
            Holiday::query()->updateOrCreate(['company_id' => $this->company->id, 'date' => $date], ['name' => $name, 'type' => 'national']);
        }
    }

    private function shifts(): void
    {
        $calc = app(ShiftCalculator::class);
        $definitions = [
            'REG' => ['Regular Shift', '08:00', '17:00', 10, 0, [['Lunch', '12:00', '13:00', 60]]],
            'AFT' => ['Afternoon Shift', '14:00', '22:00', 10, 0, [['Dinner', '18:00', '18:30', 30]]],
            'NGT' => ['Night Shift', '22:00', '06:00', 10, 0, [['Midnight break', '02:00', '03:00', 60]]],
        ];

        foreach ($definitions as $code => [$name, $start, $end, $grace, $graceEarly, $breaks]) {
            $breakRows = array_map(fn ($b) => ['duration_minutes' => $b[3], 'is_paid' => false], $breaks);
            $shift = Shift::query()->updateOrCreate(['company_id' => $this->company->id, 'code' => $code], [
                'name' => $name,
                'start_time' => $start,
                'end_time' => $end,
                'crosses_midnight' => $calc->crossesMidnight($start, $end),
                'grace_late_minutes' => $grace,
                'grace_early_leave_minutes' => $graceEarly,
                'break_minutes' => array_sum(array_column($breakRows, 'duration_minutes')),
                'working_minutes' => $calc->workingMinutes($start, $end, $breakRows),
                'is_active' => true,
            ]);
            $shift->breaks()->delete();
            foreach ($breaks as [$breakName, $bStart, $bEnd, $minutes]) {
                $shift->breaks()->create(['name' => $breakName, 'start_time' => $bStart, 'end_time' => $bEnd, 'duration_minutes' => $minutes, 'is_paid' => false]);
            }
            $this->shifts[$code] = $shift;
        }
    }

    private function organization(): void
    {
        $cid = $this->company->id;

        $standard = AttendancePolicy::query()->firstOrCreate(['company_id' => $cid, 'name' => 'Standard (GPS)'], [
            'is_default' => true, 'require_gps' => true, 'geofence_mode' => GeofenceMode::Enforce,
            'require_selfie' => false, 'max_gps_accuracy_meters' => 100, 'reject_mock_location' => true, 'offline_max_age_minutes' => 720,
        ]);
        $strict = AttendancePolicy::query()->firstOrCreate(['company_id' => $cid, 'name' => 'Strict (GPS + Selfie)'], [
            'is_default' => false, 'require_gps' => true, 'geofence_mode' => GeofenceMode::Enforce,
            'require_selfie' => true, 'max_gps_accuracy_meters' => 60, 'reject_mock_location' => true, 'offline_max_age_minutes' => 720,
        ]);

        $branches = [];
        foreach ([
            'BDG' => ['Bandung Head Office', 'Bandung', 'Jl. Asia Afrika No. 65'],
            'JKT' => ['Jakarta Branch', 'Jakarta', 'Jl. Jend. Sudirman Kav. 52'],
            'SBY' => ['Surabaya Field Office', 'Surabaya', 'Jl. Tunjungan No. 21'],
        ] as $code => [$name, $city, $address]) {
            $branches[$code] = Branch::query()->firstOrCreate(['company_id' => $cid, 'code' => $code], ['name' => $name, 'city' => $city, 'address' => $address]);
        }

        foreach ([
            'HO' => ['Head Office', 'BDG', -6.9218000, 107.6071000, 150, $standard, 'Jl. Asia Afrika No. 65, Bandung'],
            'JKT' => ['Jakarta Office', 'JKT', -6.2088000, 106.8456000, 120, $standard, 'Jl. Jend. Sudirman Kav. 52, Jakarta'],
            'SBY' => ['Surabaya Field Office', 'SBY', -7.2575000, 112.7521000, 100, $strict, 'Jl. Tunjungan No. 21, Surabaya'],
        ] as $code => [$name, $branch, $lat, $lng, $radius, $policy, $address]) {
            $this->locations[$code] = WorkLocation::query()->firstOrCreate(['company_id' => $cid, 'code' => $code], [
                'branch_id' => $branches[$branch]->id, 'attendance_policy_id' => $policy->id, 'name' => $name, 'address' => $address,
                'latitude' => $lat, 'longitude' => $lng, 'radius_meters' => $radius,
            ]);
        }

        foreach ([
            'HR' => 'Human Resources', 'ENG' => 'Engineering', 'FIN' => 'Finance', 'MKT' => 'Marketing',
            'OPS' => 'Operations', 'SAL' => 'Sales',
        ] as $code => $name) {
            $this->departments[$code] = Department::query()->firstOrCreate(
                ['company_id' => $cid, 'code' => $code],
                ['branch_id' => $branches['BDG']->id, 'name' => $name],
            );
        }

        foreach ([
            'SYSADM' => ['System Administrator', 4], 'HRM' => ['HR Manager', 4], 'HRO' => ['HR Officer', 2],
            'MKM' => ['Marketing Manager', 4], 'MKS' => ['Marketing Staff', 2], 'ENM' => ['Engineering Manager', 4],
            'SWE' => ['Software Engineer', 3], 'DVO' => ['DevOps Engineer', 3], 'FNM' => ['Finance Manager', 4],
            'FNX' => ['Finance Executive', 2], 'OPM' => ['Operations Manager', 4], 'OPS' => ['Operations Staff', 2],
            'SLX' => ['Sales Executive', 2],
        ] as $code => [$name, $level]) {
            $this->positions[$code] = Position::query()->firstOrCreate(['company_id' => $cid, 'code' => $code], ['name' => $name, 'level' => $level]);
        }
    }

    private function employees(): void
    {
        // number => [name, gender, dept, position, manager#, location, shift, type, join, status, login role, email]
        $people = [
            'EMP-0001' => ['Alex Morgan', 'male', 'ENG', 'SYSADM', null, 'HO', 'REG', 'permanent', '2023-01-09', 'ACTIVE', 'admin', 'admin@peopleos.test'],
            'EMP-0008' => ['Sarah Wilson', 'female', 'HR', 'HRM', null, 'HO', 'REG', 'permanent', '2022-03-14', 'ACTIVE', 'hr', 'hr@peopleos.test'],
            'EMP-0009' => ['Ryan Cooper', 'male', 'HR', 'HRO', 'EMP-0008', 'HO', 'REG', 'permanent', '2023-06-01', 'ACTIVE', null, null],
            'EMP-0010' => ['Amanda Clark', 'female', 'MKT', 'MKM', null, 'HO', 'REG', 'permanent', '2022-08-22', 'ACTIVE', 'manager', 'manager@peopleos.test'],
            'EMP-0011' => ['Grace Hall', 'female', 'FIN', 'FNM', null, 'HO', 'REG', 'permanent', '2021-11-15', 'ACTIVE', null, null],
            'EMP-0012' => ['Michael Carter', 'male', 'MKT', 'MKS', 'EMP-0010', 'HO', 'REG', 'permanent', '2022-01-15', 'ACTIVE', 'employee', 'employee@peopleos.test'],
            'EMP-0013' => ['Olivia Martin', 'female', 'MKT', 'MKS', 'EMP-0010', 'HO', 'REG', 'contract', '2024-02-05', 'ACTIVE', 'employee', 'olivia@peopleos.test'],
            'EMP-0014' => ['Kevin Tan', 'male', 'ENG', 'ENM', null, 'HO', 'REG', 'permanent', '2021-05-03', 'ACTIVE', 'manager', 'kevin@peopleos.test'],
            'EMP-0015' => ['Daniel Brooks', 'male', 'ENG', 'SWE', 'EMP-0014', 'HO', 'REG', 'permanent', '2023-04-17', 'ACTIVE', null, null],
            'EMP-0016' => ['Christopher Lee', 'male', 'ENG', 'DVO', 'EMP-0014', 'HO', 'NGT', 'permanent', '2023-09-11', 'ACTIVE', null, null],
            'EMP-0017' => ['Emily Johnson', 'female', 'FIN', 'FNX', 'EMP-0011', 'JKT', 'REG', 'permanent', '2022-10-03', 'ACTIVE', null, null],
            'EMP-0018' => ['James Anderson', 'male', 'OPS', 'OPM', null, 'JKT', 'REG', 'permanent', '2021-07-19', 'ACTIVE', null, null],
            'EMP-0019' => ['Jessica Taylor', 'female', 'SAL', 'SLX', 'EMP-0018', 'SBY', 'REG', 'permanent', '2023-02-13', 'ACTIVE', 'employee', 'jessica@peopleos.test'],
            'EMP-0020' => ['Nathan Reed', 'male', 'SAL', 'SLX', 'EMP-0018', 'SBY', 'AFT', 'contract', '2024-05-20', 'ACTIVE', null, null],
            'EMP-0021' => ['Sophia Nguyen', 'female', 'OPS', 'OPS', 'EMP-0018', 'JKT', 'REG', 'permanent', '2024-01-08', 'ACTIVE', null, null],
            'EMP-0022' => ['Liam Park', 'male', 'ENG', 'SWE', 'EMP-0014', 'HO', 'REG', 'permanent', '2022-12-05', 'INACTIVE', null, null],
            'EMP-0023' => ['Noah Davis', 'male', 'SAL', 'SLX', 'EMP-0018', 'SBY', 'REG', 'permanent', '2022-04-11', 'TERMINATED', null, null],
            'EMP-0024' => ['Ava Thompson', 'female', 'MKT', 'MKS', 'EMP-0010', 'HO', 'REG', 'probation', '2026-10-05', 'PENDING', null, null],
        ];

        // Two passes so managers exist before their reports reference them.
        foreach ([false, true] as $linkManagers) {
            foreach ($people as $number => [$name, $gender, $dept, $pos, $mgr, $loc, $shift, $type, $join, $status, $role, $email]) {
                $employee = $this->employees[$number] ?? null;

                if (! $employee) {
                    $user = null;
                    if ($role) {
                        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'is_active' => $status === 'ACTIVE']);
                        $user->assignRole($role);
                    }
                    $employee = Employee::query()->create([
                        'company_id' => $this->company->id, 'user_id' => $user?->id, 'employee_number' => $number,
                        'full_name' => $name, 'gender' => $gender, 'employment_type' => $type, 'join_date' => $join,
                        'status' => $status, 'email' => $email ?? strtolower(str_replace(' ', '.', $name)).'@peopleos.test',
                        'phone' => '+62 812 '.random_int(1000, 9999).' '.random_int(1000, 9999),
                        'date_of_birth' => CarbonImmutable::parse('1988-01-01')->addDays(random_int(0, 3650))->toDateString(),
                        'address' => 'Jl. Melati No. '.random_int(1, 90).', Bandung',
                        'terminated_at' => $status === 'TERMINATED' ? '2026-07-31' : null,
                        'termination_reason' => $status === 'TERMINATED' ? 'Resigned' : null,
                    ]);
                    $this->employees[$number] = $employee;
                }

                if ($linkManagers) {
                    EmployeeAssignment::query()->firstOrCreate(['employee_id' => $employee->id, 'effective_from' => $join], [
                        'branch_id' => $this->locations[$loc]->branch_id,
                        'department_id' => $this->departments[$dept]->id,
                        'position_id' => $this->positions[$pos]->id,
                        'manager_id' => $mgr ? $this->employees[$mgr]->id : null,
                        'work_location_id' => $this->locations[$loc]->id,
                        'shift_id' => $this->shifts[$shift]->id,
                        'effective_to' => $status === 'TERMINATED' ? '2026-07-31' : null,
                        'reason' => 'Initial assignment',
                    ]);
                }
            }
        }
    }

    private function attendanceHistory(): void
    {
        $context = app(CompanyContext::class);
        $schedules = app(ScheduleService::class);
        $attendanceService = app(AttendanceService::class);
        $today = $context->today();

        $active = collect($this->employees)->filter(fn (Employee $e) => in_array($e->status, [EmployeeStatus::Active, EmployeeStatus::Terminated], true));
        $keepFreshToday = ['EMP-0012', 'EMP-0010', 'EMP-0008', 'EMP-0001', 'EMP-0014', 'EMP-0013', 'EMP-0019'];

        foreach ($active as $number => $employee) {
            $lastDay = $employee->status === EmployeeStatus::Terminated ? CarbonImmutable::parse('2026-07-31') : $today;
            $from = max($today->subDays(21), CarbonImmutable::parse($employee->join_date));

            for ($day = $from; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
                $isToday = $day->isSameDay($today);
                if ($isToday && in_array($number, $keepFreshToday, true)) {
                    continue; // leave demo logins un-checked-in so the flow can be demonstrated
                }

                $schedule = $schedules->forDate($employee, $day->toDateString());
                if (! $schedule->is_working_day) {
                    continue;
                }

                $roll = mt_rand(1, 100);
                if ($roll <= 4 && ! $isToday) {
                    continue; // absent
                }

                $lateBy = $roll <= 20 ? mt_rand(12, 45) : mt_rand(-12, 4);
                $checkIn = CarbonImmutable::instance($schedule->expected_start_at)->addMinutes($lateBy);

                if ($isToday) {
                    if ($checkIn->greaterThan(now())) {
                        continue;
                    }
                    $checkOut = null;
                } else {
                    $checkOut = CarbonImmutable::instance($schedule->expected_end_at)->addMinutes(mt_rand(-3, 25));
                }

                $this->storeAttendance($employee, $schedule, $checkIn, $checkOut, $attendanceService);
            }
        }
    }

    private function storeAttendance(Employee $employee, $schedule, CarbonImmutable $checkIn, ?CarbonImmutable $checkOut, AttendanceService $service): Attendance
    {
        $attendance = new Attendance([
            'employee_id' => $employee->id,
            'attendance_schedule_id' => $schedule->id,
            'work_date' => $schedule->work_date->toDateString(),
            'status' => $checkOut ? AttendanceStatus::CheckedOut : AttendanceStatus::CheckedIn,
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
        ]);
        $service->recalculate($attendance, $schedule);
        $attendance->save();

        foreach ([[AttendanceEventType::CheckIn, $checkIn], [AttendanceEventType::CheckOut, $checkOut]] as [$type, $at]) {
            if (! $at) {
                continue;
            }
            [$lat, $lng] = $this->jitter((float) $schedule->location_latitude, (float) $schedule->location_longitude, (int) $schedule->location_radius_meters);
            AttendanceEvent::query()->create([
                'attendance_id' => $attendance->id,
                'employee_id' => $employee->id,
                'type' => $type,
                'idempotency_key' => sprintf('demo-%d-%s-%s', $employee->id, $schedule->work_date->toDateString(), $type->value),
                'occurred_at' => $at,
                'received_at' => $at,
                'time_source' => 'server',
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy_meters' => mt_rand(60, 250) / 10,
                'location_captured_at' => $at,
                'distance_meters' => mt_rand(5, max(6, (int) ($schedule->location_radius_meters * 0.7))),
                'inside_geofence' => true,
            ]);
        }

        return $attendance;
    }

    /** @return array{0: float, 1: float} */
    private function jitter(float $lat, float $lng, int $radius): array
    {
        $meters = mt_rand(0, (int) ($radius * 0.6));
        $bearing = deg2rad(mt_rand(0, 359));

        return [
            round($lat + ($meters * cos($bearing)) / 111320, 7),
            round($lng + ($meters * sin($bearing)) / (111320 * cos(deg2rad($lat))), 7),
        ];
    }

    /** Pending items so the review screens are not empty on a fresh install. */
    private function requests(): void
    {
        $michael = $this->employees['EMP-0012'];
        $olivia = $this->employees['EMP-0013'];
        $amanda = $this->employees['EMP-0010'];

        // Michael forgot to check out three working days ago -> correction pending.
        $target = Attendance::query()->where('employee_id', $michael->id)->whereDate('work_date', '<', now()->toDateString())
            ->orderByDesc('work_date')->skip(2)->first();
        if ($target && $target->check_out_at) {
            $original = $target->check_out_at;
            $target->update(['check_out_at' => null, 'status' => AttendanceStatus::CorrectionRequested, 'status_before_correction' => AttendanceStatus::CheckedIn, 'worked_minutes' => 0, 'early_leave_minutes' => 0, 'is_early_leave' => false]);
            AttendanceEvent::query()->where('attendance_id', $target->id)->where('type', AttendanceEventType::CheckOut->value)->delete();
            AttendanceCorrection::query()->create([
                'employee_id' => $michael->id, 'attendance_id' => $target->id, 'attendance_schedule_id' => $target->attendance_schedule_id,
                'work_date' => $target->work_date, 'requested_check_out_at' => $original, 'reason' => 'Forgot to check out before leaving the office.',
                'status' => RequestStatus::Pending, 'requested_by' => $michael->user_id,
            ]);
        }

        // Olivia's pending correction and overtime waiting for Amanda (her manager).
        $oliviaDay = Attendance::query()->where('employee_id', $olivia->id)->orderByDesc('work_date')->skip(4)->first();
        if ($oliviaDay) {
            AttendanceCorrection::query()->create([
                'employee_id' => $olivia->id, 'attendance_id' => $oliviaDay->id, 'attendance_schedule_id' => $oliviaDay->attendance_schedule_id,
                'work_date' => $oliviaDay->work_date, 'requested_check_in_at' => $oliviaDay->check_in_at->subMinutes(20),
                'reason' => 'Phone battery died, arrived earlier than recorded.', 'status' => RequestStatus::Pending, 'requested_by' => $olivia->user_id,
            ]);
        }

        $ot = CarbonImmutable::now()->subDays(2)->setTime(17, 0)->utc();
        OvertimeRequest::query()->create([
            'employee_id' => $olivia->id, 'work_date' => $ot->toDateString(), 'start_at' => $ot, 'end_at' => $ot->addHours(2),
            'requested_minutes' => 120, 'reason' => 'Campaign launch preparation.', 'status' => RequestStatus::Pending, 'requested_by' => $olivia->user_id,
        ]);
        OvertimeRequest::query()->create([
            'employee_id' => $michael->id, 'work_date' => $ot->subDays(6)->toDateString(), 'start_at' => $ot->subDays(6), 'end_at' => $ot->subDays(6)->addMinutes(90),
            'requested_minutes' => 90, 'approved_minutes' => 90, 'reason' => 'Quarterly report deadline.', 'status' => RequestStatus::Approved,
            'requested_by' => $michael->user_id, 'reviewed_by' => $amanda->user_id, 'reviewed_at' => now()->subDays(5),
        ]);
    }
}
