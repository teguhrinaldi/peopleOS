<?php

namespace Database\Seeders;

use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Services\Leave\LeaveSetupService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo leave types and a realistic set of requests, created through the real LeaveService (with the
 * clock moved back for historical ones) so balances, calendar slots, audit and notifications all agree.
 * Leave types and allowances are examples only; configure your own in Leave Types.
 */
class LeaveDemoSeeder extends Seeder
{
    /** @var array<string, LeaveType> */
    private array $types = [];

    public function run(): void
    {
        $context = app(CompanyContext::class);
        $hr = User::query()->where('email', 'hr@peopleos.test')->firstOrFail();

        $this->createTypes($hr);

        $today = $context->today();

        // [employee number, type, start offset (calendar days), length (working days), half day, reason, outcome, decided by]
        $plan = [
            ['EMP-0012', 'ANNUAL', -46, 3, null, 'Family trip to Yogyakarta', 'APPROVED', 'manager@peopleos.test'],
            ['EMP-0012', 'ANNUAL', 14, 3, null, 'Sister\'s wedding in Medan', 'PENDING', null],
            ['EMP-0013', 'ANNUAL', 7, 3, null, 'Short holiday in Bali', 'PENDING', null],
            ['EMP-0013', 'SICK', -12, 1, null, 'Fever, doctor\'s note provided', 'APPROVED', 'manager@peopleos.test'],
            ['EMP-0015', 'SICK', -9, 2, null, 'Flu', 'APPROVED', 'kevin@peopleos.test'],
            ['EMP-0017', 'ANNUAL', -25, 3, null, 'Month-end close conflict', 'REJECTED', 'hr@peopleos.test'],
            ['EMP-0019', 'ANNUAL', 21, 3, null, 'Visiting parents in Malang', 'APPROVED', 'hr@peopleos.test'],
            ['EMP-0021', 'UNPAID', -31, 2, null, 'Moving house', 'APPROVED', 'hr@peopleos.test'],
            ['EMP-0014', 'PERSONAL', -5, 1, 'PM', 'School meeting for my son', 'APPROVED', 'hr@peopleos.test'],
            ['EMP-0018', 'ANNUAL', 10, 1, null, 'Personal errands', 'PENDING', null],
            ['EMP-0020', 'ANNUAL', -17, 2, null, 'Changed plans', 'CANCELLED', null],
        ];

        if (LeaveRequest::query()->exists()) {
            $this->command?->info('Leave requests already present; demo requests skipped.');

            return;
        }

        foreach ($plan as [$number, $code, $offset, $length, $half, $reason, $outcome, $decider]) {
            $start = $this->nextWorkday($today->addDays($offset));
            $end = $start;
            for ($i = 1; $i < $length; $i++) {
                $end = $this->nextWorkday($end->addDay());
            }
            try {
                $this->createRequest($number, $code, $start, $end, $half, $reason, $outcome, $decider);
            } catch (ApiException $e) {
                // Relative dates can land on a public holiday depending on the seed day; skip rather than fail.
                Carbon::setTestNow();
                $this->command?->warn("Skipped demo leave for {$number}: {$e->errorCode}");
            }
        }

        Carbon::setTestNow();
        $context->forget();
    }

    private function createTypes(User $hr): void
    {
        $setup = app(LeaveSetupService::class);
        $definitions = [
            'ANNUAL' => [['name' => 'Annual Leave', 'is_paid' => true, 'color' => 'blue', 'sort_order' => 1, 'description' => 'Paid holiday entitlement.'],
                ['annual_allowance' => 12, 'carry_over_max' => 6, 'min_notice_days' => 3, 'allow_half_day' => true, 'requires_reason' => false]],
            'SICK' => [['name' => 'Sick Leave', 'is_paid' => true, 'color' => 'orange', 'sort_order' => 2, 'description' => 'Illness or medical treatment.'],
                ['annual_allowance' => null, 'max_past_days' => 7, 'allow_half_day' => true, 'requires_reason' => true]],
            'PERSONAL' => [['name' => 'Personal Leave', 'is_paid' => true, 'color' => 'purple', 'sort_order' => 3, 'description' => 'Short personal or family matters.'],
                ['annual_allowance' => 3, 'prorate_first_year' => false, 'min_notice_days' => 1, 'max_consecutive_days' => 2, 'requires_reason' => true]],
            'UNPAID' => [['name' => 'Unpaid Leave', 'is_paid' => false, 'color' => 'gray', 'sort_order' => 4, 'description' => 'Time off without pay, deducted in payroll.'],
                ['annual_allowance' => null, 'min_notice_days' => 3, 'requires_reason' => true]],
            'OTHER' => [['name' => 'Other Leave', 'is_paid' => true, 'color' => 'teal', 'sort_order' => 5, 'description' => 'Bereavement, marriage and other special leave approved by HR.'],
                ['annual_allowance' => null, 'max_past_days' => 3, 'requires_reason' => true]],
        ];

        foreach ($definitions as $code => [$type, $policy]) {
            $existing = LeaveType::query()->where('code', $code)->first();
            $this->types[$code] = $existing ?? $setup->createType(['code' => $code] + $type, $policy, $hr);
        }

        // Contract staff accrue less annual leave: an employment-type specific policy.
        $setup->savePolicy($this->types['ANNUAL'], ['employment_type' => 'contract', 'annual_allowance' => 10, 'carry_over_max' => 0, 'min_notice_days' => 3, 'requires_reason' => false], $hr);
    }

    private function createRequest(string $number, string $code, CarbonImmutable $start, CarbonImmutable $end, ?string $half, string $reason, string $outcome, ?string $decider): void
    {
        $employee = Employee::query()->where('employee_number', $number)->firstOrFail();
        $owner = $employee->user ?? User::query()->where('email', 'hr@peopleos.test')->firstOrFail();
        $service = app(LeaveService::class);

        // Submit as of a week before the leave starts (or today for future requests), then decide a day later.
        $submittedAt = $start->isFuture() ? CarbonImmutable::now() : $start->subDays(7)->setTime(9, 15);
        Carbon::setTestNow($submittedAt);
        app(CompanyContext::class)->forget();

        $request = $service->create($employee, $owner, [
            'leave_type_id' => $this->types[$code]->id, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'half_day' => $half, 'reason' => $reason,
        ]);

        if ($outcome === 'PENDING') {
            Carbon::setTestNow();

            return;
        }

        Carbon::setTestNow($start->isFuture() ? CarbonImmutable::now() : $submittedAt->addDay()->setTime(10, 30));
        match ($outcome) {
            'APPROVED' => $service->approve(User::query()->where('email', $decider)->firstOrFail(), $request, 'Approved'),
            'REJECTED' => $service->reject(User::query()->where('email', $decider)->firstOrFail(), $request, 'Finance month-end close needs full attendance that week. Please pick other dates.'),
            'CANCELLED' => $service->cancel($owner, $request, 'Plans changed'),
        };
        Carbon::setTestNow();

        if ($outcome === 'APPROVED' && $half === null) {
            $this->removeGeneratedAttendance($request->refresh());
        }
    }

    /** DemoSeeder generated attendance for every past workday; nobody checks in on a full day of leave. */
    private function removeGeneratedAttendance(LeaveRequest $request): void
    {
        Attendance::query()
            ->where('employee_id', $request->employee_id)
            ->whereBetween('work_date', [$request->start_date->toDateString(), $request->end_date->toDateString()])
            ->get()
            ->each(fn (Attendance $a) => $a->delete()); // events cascade
    }

    private function nextWorkday(CarbonImmutable $day): CarbonImmutable
    {
        while ($day->isWeekend()) {
            $day = $day->addDay();
        }

        return $day;
    }
}
