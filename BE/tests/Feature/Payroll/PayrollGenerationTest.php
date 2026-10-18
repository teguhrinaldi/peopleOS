<?php

namespace Tests\Feature\Payroll;

use App\Enums\EmployeeStatus;
use App\Models\Activity;
use App\Models\EmployeeSalaryComponent;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\Money;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class PayrollGenerationTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-09-30 10:00');
    }

    private function item(int $employeeId, int $periodId): PayrollItem
    {
        return PayrollItem::query()->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->where('payroll_period_id', $periodId))->firstOrFail();
    }

    public function test_effective_dated_salary_june_uses_old_value_july_uses_new(): void
    {
        [, $michael] = $this->makeStaff();
        $this->giveSalary($michael, '8000000', '2026-01-01', []);
        app(EmployeeSalaryService::class)->change($this->hr, $michael, '2026-07-01', [
            ['salary_component_id' => $this->components['BASIC']->id, 'amount' => '9000000'],
        ], 'Annual raise');

        $june = $this->generate($this->period('2026-06-01', '2026-06-30'));
        $july = $this->generate($this->period('2026-07-01', '2026-07-31'));

        $this->assertSame('8000000.00', (string) $this->item($michael->id, $june->id)->basic_salary);
        $this->assertSame('9000000.00', (string) $this->item($michael->id, $july->id)->basic_salary);

        // history was closed, not overwritten
        $rows = $michael->hasMany(EmployeeSalaryComponent::class)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-06-30', $rows[0]->effective_to->toDateString());
        $this->assertSame('8000000.00', (string) $rows[0]->amount);
    }

    public function test_mid_period_change_applies_the_value_in_force_on_the_period_end(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '6000000', '2026-01-01', []);
        app(EmployeeSalaryService::class)->change($this->hr, $e, '2026-07-16', [
            ['salary_component_id' => $this->components['BASIC']->id, 'amount' => '6600000'],
        ], 'Promotion');

        $july = $this->generate($this->period());

        $item = $this->item($e->id, $july->id);
        $this->assertSame('6600000.00', (string) $item->basic_salary, 'no pro-rating: value on the period end date');
        $this->assertSame('2026-07-31', $item->salary_reference_date->toDateString());
    }

    public function test_inputs_present_days_overtime_and_unpaid_leave_feed_the_lines(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '6900000', '2026-01-01', ['TRANSPORT' => '50000', 'OVERTIME' => '40000', 'UNPAID' => '0', 'COOP' => '100000']);
        $this->present($e, ['2026-07-01', '2026-07-02', '2026-07-03']);
        $this->present($e, ['2026-07-06'], 'REJECTED'); // not counted
        $this->approvedOvertime($e, '2026-07-02', 90);
        $this->unpaidLeave($e, ['2026-07-07']);

        $period = $this->generate($this->period());
        $item = $this->item($e->id, $period->id);
        $lines = $item->lines->keyBy('component_code');

        $this->assertSame(23, $item->working_days); // July 2026: 23 weekdays, no holidays in fixtures
        $this->assertSame(3, $item->present_days);
        $this->assertSame(90, $item->overtime_minutes);
        $this->assertSame('1.0', (string) $item->unpaid_leave_days);
        $this->assertSame('150000.00', (string) $lines['TRANSPORT']->amount);
        $this->assertSame('60000.00', (string) $lines['OVERTIME']->amount);
        $this->assertSame('300000.00', (string) $lines['UNPAID']->amount); // 6,900,000 / 23 * 1
        $this->assertSame('100000.00', (string) $lines['COOP']->amount);
        $this->assertSame('7110000.00', (string) $item->gross_pay);
        $this->assertSame('6710000.00', (string) $item->net_pay);

        // snapshot of each line
        $this->assertSame('PER_PRESENT_DAY', $lines['TRANSPORT']->calculation);
        $this->assertSame('3.00', (string) $lines['TRANSPORT']->quantity);
        $this->assertSame('Transport Allowance', $lines['TRANSPORT']->component_name);
        $this->assertSame('Person 3', $item->employee_name);
        $this->assertSame('Marketing', $item->department_name);
    }

    public function test_inclusion_rules_for_terminated_inactive_pending_and_unsalaried_employees(): void
    {
        [, $active] = $this->makeStaff();
        [, $leftJuly] = $this->makeStaff('employee', ['status' => EmployeeStatus::Terminated->value, 'terminated_at' => '2026-07-15']);
        [, $leftJune] = $this->makeStaff('employee', ['status' => EmployeeStatus::Terminated->value, 'terminated_at' => '2026-06-30']);
        [, $inactive] = $this->makeStaff('employee', ['status' => EmployeeStatus::Inactive->value]);
        [, $joinsAugust] = $this->makeStaff('employee', ['join_date' => '2026-08-03', 'status' => EmployeeStatus::Pending->value]);
        [, $unsalaried] = $this->makeStaff();
        foreach ([$active, $leftJuly, $leftJune, $inactive, $joinsAugust] as $e) {
            $this->giveSalary($e, '5000000', '2026-01-01', []);
        }

        $july = $this->generate($this->period());
        $included = PayrollItem::query()->where('payroll_run_id', $july->run->id)->pluck('employee_id')->all();

        $this->assertContains($active->id, $included);
        $this->assertContains($leftJuly->id, $included, 'terminated mid-period: historical processing');
        $this->assertNotContains($leftJune->id, $included, 'never paid after termination');
        $this->assertNotContains($inactive->id, $included);
        $this->assertNotContains($joinsAugust->id, $included);
        $this->assertNotContains($unsalaried->id, $included);
        $this->assertSame('2026-07-15', $this->item($leftJuly->id, $july->id)->salary_reference_date->toDateString());

        $skipped = collect($july->run->summary['skipped'])->pluck('reason', 'employee_id');
        $this->assertSame('NO_SALARY_COMPONENTS', $skipped[$unsalaried->id], 'skipped with a warning, not silently');
        $this->assertSame('EMPLOYEE_INACTIVE', $skipped[$inactive->id]);

        $august = $this->generate($this->period('2026-08-01', '2026-08-31'));
        $augIds = PayrollItem::query()->where('payroll_run_id', $august->run->id)->pluck('employee_id')->all();
        $this->assertNotContains($leftJuly->id, $augIds, 'a later period never includes a terminated employee');
    }

    public function test_regeneration_is_idempotent_and_replaces_items_atomically(): void
    {
        [, $a] = $this->makeStaff();
        [, $b] = $this->makeStaff();
        $this->giveSalary($a, '5000000');
        $this->giveSalary($b, '6000000');
        $period = $this->period();

        $this->generate($period);
        $firstIds = PayrollItem::query()->pluck('id')->all();
        $this->generate($period); // DRAFT -> regenerate
        app(PayrollWorkflow::class)->submit($this->hr, $period);
        $this->generate($period); // REVIEW -> regenerate (back to DRAFT)

        $period->refresh();
        $this->assertSame('DRAFT', $period->status->value);
        $this->assertSame(2, PayrollItem::query()->count());
        $this->assertSame(2, PayrollItem::query()->distinct()->count('employee_id'));
        $this->assertEmpty(array_intersect($firstIds, PayrollItem::query()->pluck('id')->all()));
        $this->assertSame(PayrollItem::query()->count() * 2, PayrollItemLine::query()->count()); // BASIC + COOP each (no attendance)
        $this->assertNull($period->run->submitted_by, 'regeneration requires a fresh submission');
        $this->assertSame(1, Activity::query()->where('action', 'payroll.generated')->count());
        $this->assertSame(2, Activity::query()->where('action', 'payroll.regenerated')->count());
    }

    public function test_run_totals_derive_from_the_stored_items(): void
    {
        foreach (['5000000', '7250000', '12000000'] as $basic) {
            [, $e] = $this->makeStaff();
            $this->giveSalary($e, $basic);
        }
        $run = $this->generate($this->period())->run;

        $items = PayrollItem::query()->where('payroll_run_id', $run->id)->get();
        $this->assertSame(3, $run->employee_count);
        $this->assertSame(0, Money::cmp((string) $run->total_gross, Money::add(...$items->pluck('gross_pay')->map(fn ($v) => (string) $v)->all())));
        $this->assertSame(0, Money::cmp((string) $run->total_net, Money::add(...$items->pluck('net_pay')->map(fn ($v) => (string) $v)->all())));
        $this->assertSame('23950000.00', (string) $run->total_net); // 24.25M - 3 x 100k
    }

    public function test_a_duplicate_payroll_item_is_impossible_at_database_level(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '5000000');
        $run = $this->generate($this->period())->run;
        $existing = PayrollItem::query()->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);
        PayrollItem::query()->create($existing->only(['employee_number', 'employee_name']) + [
            'payroll_run_id' => $run->id, 'employee_id' => $e->id,
        ]);
    }

    public function test_rounding_follows_the_settings_key(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '7000000', '2026-01-01', ['UNPAID' => '0']);
        $this->unpaidLeave($e, ['2026-07-07']);

        $run = $this->generate($this->period())->run; // IDR auto -> 0 decimals
        $this->assertSame(0, $run->decimals);
        $this->assertSame('304348.00', (string) PayrollItemLine::query()->where('component_code', 'UNPAID')->value('amount')); // 7,000,000/23 = 304,347.83

        app(Settings::class)->set(['payroll.rounding_decimals' => '2']);
        $run = $this->generate($run->period)->run;
        $this->assertSame(2, $run->decimals);
        $this->assertSame('304347.83', (string) PayrollItemLine::query()->where('component_code', 'UNPAID')->value('amount'));
    }
}
