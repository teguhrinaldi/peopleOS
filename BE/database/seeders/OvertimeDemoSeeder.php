<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\PayrollPeriod;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\OvertimeService;
use App\Services\Attendance\ScheduleService;
use App\Services\Overtime\OvertimeRuleService;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\CompanyContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * SAFE, idempotent overtime-engine demo for an EXISTING dev database (also called at the end of PayrollDemoSeeder):
 *
 *   php artisan migrate
 *   php artisan db:seed --class=RolesAndPermissionsSeeder   (adds overtime.manage)
 *   php artisan db:seed --class=OvertimeDemoSeeder
 *
 * It never touches finalized or paid payroll. It:
 *  1. creates the demo overtime rule "Standard overtime (demo)" (basic / 173; workday 1st hour x1.5 then x2;
 *     rest day x2 up to 8 h then x3; holiday x3) unless a rule already exists - demo values, NOT statutory;
 *  2. switches Settings overtime.engine_enabled on;
 *  3. creates the next OPEN regular period after the last finalized/paid one (normally October 2026) unless the
 *     scenario already exists, with overtime through the real request -> approve flow:
 *       - EMP-0015 workday 17:30-20:30, attendance until 20:35        -> verified 180 min, paid
 *       - EMP-0016 rest day (Saturday) 09:00-13:00, attended            -> verified 240 min, paid at the rest-day rate
 *       - EMP-0012 workday 17:30-19:30 approved, left 18:30             -> verified 60 of 120 min, 60 paid
 *       - EMP-0019 workday 21:00-22:00 approved, no attendance then     -> NOT_VERIFIED, excluded (stays pending)
 *       - EMP-0021 overtime on a FINALIZED/PAID month approved late     -> carried over into the open period
 *     then generates the period and submits it to REVIEW.
 * Attendance rows of the overtime dates are extended (or created) so the cross-check can verify them; payroll rows
 * of locked periods are never written.
 */
class OvertimeDemoSeeder extends Seeder
{
    public const MARKER = 'Demo: release crunch (overtime engine)';

    public function run(): void
    {
        $company = Company::query()->where('name', 'PeopleOS Demo Company')->first();
        $hr = User::query()->where('email', 'hr@peopleos.test')->first();
        if (! $company || ! $hr || ! SalaryComponent::query()->where('company_id', $company->id)->exists()) {
            $this->command?->warn('OvertimeDemoSeeder: run DemoSeeder and PayrollDemoSeeder first.');

            return;
        }
        app(CompanyContext::class)->forget();

        if (! OvertimeRule::query()->where('company_id', $company->id)->exists()) {
            app(OvertimeRuleService::class)->create($hr, self::demoRule());
        }
        app(Settings::class)->set(['overtime.engine_enabled' => true]);

        if (OvertimeRequest::query()->where('reason', self::MARKER)->exists()) {
            $this->command?->info('OvertimeDemoSeeder: scenario already present, nothing to do.');

            return;
        }

        $last = PayrollPeriod::query()->where('company_id', $company->id)->where('type', 'REGULAR')
            ->where('status', '!=', PayrollStatus::Cancelled->value)->orderByDesc('end_date')->first();
        $lastLocked = PayrollPeriod::query()->where('company_id', $company->id)->where('type', 'REGULAR')
            ->whereIn('status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value])->orderByDesc('end_date')->first();
        if (! $last || ! $lastLocked) {
            $this->command?->warn('OvertimeDemoSeeder: no finalized payroll yet, scenario skipped.');

            return;
        }

        $start = CarbonImmutable::parse($last->end_date)->addDay()->startOfMonth();
        $period = app(PayrollPeriodService::class)->createRegular($hr, $start->toDateString(), $start->endOfMonth()->toDateString(), null, $start->endOfMonth()->toDateString(), 'Monthly payroll (overtime engine demo)');

        $tz = $company->timezone ?: 'Asia/Jakarta';
        $workdays = $this->days($start, $tz, false);
        $saturday = $this->days($start, $tz, true)[0];
        $scenario = [
            ['EMP-0015', $workdays[0], '17:30', 180, '20:35'],
            ['EMP-0016', $saturday, '09:00', 240, '13:05'],
            ['EMP-0012', $workdays[1], '17:30', 120, '18:30'],
            ['EMP-0019', $workdays[2], '21:00', 60, null],
        ];
        $lockedEnd = CarbonImmutable::parse($lastLocked->end_date);
        $lateDate = $lockedEnd->subDays(3);
        while ($lateDate->isWeekend()) {
            $lateDate = $lateDate->subDay();
        }
        $scenario[] = ['EMP-0021', $lateDate->toDateString(), '17:30', 120, '19:40'];

        foreach ($scenario as [$number, $date, $from, $minutes, $out]) {
            $employee = Employee::query()->where('company_id', $company->id)->where('employee_number', $number)->first();
            if (! $employee) {
                continue;
            }
            if ($out !== null) {
                $this->attend($employee, $date, $out, $tz);
            }
            $this->approvedRequest($employee, $hr, $date, CarbonImmutable::parse("{$date} {$from}", $tz)->utc(), $minutes);
        }

        app(PayrollGenerator::class)->generate($hr, $period);
        app(PayrollWorkflow::class)->submit($hr, $period);
        $this->command?->info("OvertimeDemoSeeder: {$period->name} generated with overtime lines and submitted for review.");
    }

    /** @return array<string, mixed> */
    public static function demoRule(): array
    {
        return [
            'name' => 'Standard overtime (demo)', 'effective_from' => '2026-01-01', 'rate_basis' => 'BASIC', 'rate_divisor' => 173,
            'multiplier_tiers' => [
                'workday' => [['up_to_hours' => 1, 'multiplier' => 1.5], ['multiplier' => 2]],
                'rest_day' => [['up_to_hours' => 8, 'multiplier' => 2], ['multiplier' => 3]],
                'holiday' => [['multiplier' => 3]],
            ],
            'rounding_minutes' => 1, 'rounding_direction' => 'DOWN', 'max_minutes_per_day' => 240, 'max_minutes_per_period' => 3600,
            'notes' => 'Demo values only. Configure the rules of your jurisdiction (docs/overtime.md).',
        ];
    }

    /** First working days (Mon-Fri) or Saturdays of the month that are already in the past. @return list<string> */
    private function days(CarbonImmutable $month, string $tz, bool $saturday): array
    {
        $today = CarbonImmutable::now($tz)->startOfDay();
        $out = [];
        for ($d = $month; $d->month === $month->month && count($out) < 3; $d = $d->addDay()) {
            if (($saturday ? $d->isSaturday() : $d->isWeekday()) && $d->lessThan($today)) {
                $out[] = $d->toDateString();
            }
        }

        return $out ?: [$month->addDays($saturday ? 5 : 1)->toDateString()];
    }

    /** Make the attendance of the date end at $out (local), creating the row from the schedule when needed. */
    private function attend(Employee $employee, string $date, string $out, string $tz): void
    {
        $schedule = app(ScheduleService::class)->forDate($employee, $date);
        $row = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->first()
            ?? new Attendance([
                'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $date,
                'check_in_at' => $schedule->is_working_day ? $schedule->expected_start_at : CarbonImmutable::parse("{$date} 08:55", $tz)->utc(),
            ]);
        $row->fill(['status' => AttendanceStatus::CheckedOut, 'check_out_at' => CarbonImmutable::parse("{$date} {$out}", $tz)->utc()]);
        app(AttendanceService::class)->recalculate($row, $schedule);
        $row->save();
    }

    private function approvedRequest(Employee $employee, User $hr, string $date, CarbonImmutable $start, int $minutes): void
    {
        $request = OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'work_date' => $date, 'start_at' => $start, 'end_at' => $start->addMinutes($minutes),
            'requested_minutes' => $minutes, 'reason' => self::MARKER, 'status' => RequestStatus::Pending,
            'requested_by' => $employee->user_id,
        ]);
        app(OvertimeService::class)->approve($hr, $request, null, 'Approved (demo)');
    }
}
