<?php

namespace Tests\Unit;

use App\Enums\SalaryCalculation as C;
use App\Enums\SalaryComponentKind as K;
use App\Services\Payroll\Calculation\ComponentValue;
use App\Services\Payroll\Calculation\ManualEntry;
use App\Services\Payroll\Calculation\PayrollCalculator;
use App\Services\Payroll\Calculation\PayrollInput;
use App\Services\Payroll\Calculation\PayrollResult;
use App\Services\Payroll\Money;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private PayrollCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PayrollCalculator;
    }

    private function basic(string $amount = '8000000'): ComponentValue
    {
        return new ComponentValue(1, 'BASIC', 'Basic Salary', K::Earning, C::Fixed, true, $amount);
    }

    private function c(string $code, K $kind, C $calc, ?string $amount = null, ?string $rate = null, int $sort = 10): ComponentValue
    {
        return new ComponentValue(crc32($code), $code, $code, $kind, $calc, false, $amount, $rate, $sort);
    }

    /** @param list<ComponentValue> $components */
    private function compute(array $components, array $args = []): PayrollResult
    {
        return $this->calc->calculate(new PayrollInput($components, ...$args));
    }

    private function amountOf(PayrollResult $r, string $code): ?string
    {
        foreach ($r->lines as $l) {
            if ($l->code === $code) {
                return $l->amount;
            }
        }

        return null;
    }

    public function test_fixed_basic_only_gross_equals_net(): void
    {
        $r = $this->compute([$this->basic()]);

        $this->assertSame('8000000', $r->basic);
        $this->assertSame('8000000', $r->gross);
        $this->assertSame('0', $r->deductions);
        $this->assertSame('8000000', $r->net);
        $this->assertCount(1, $r->lines);
        $this->assertSame([], $r->warnings);
    }

    public function test_fixed_earning_and_fixed_deduction(): void
    {
        $r = $this->compute([
            $this->basic(),
            $this->c('POS', K::Earning, C::Fixed, '1500000'),
            $this->c('COOP', K::Deduction, C::Fixed, '100000'),
        ]);

        $this->assertSame('9500000', $r->gross);
        $this->assertSame('100000', $r->deductions);
        $this->assertSame('9400000', $r->net);
    }

    public function test_percent_of_basic(): void
    {
        $r = $this->compute([$this->basic('8000000'), $this->c('PCT', K::Earning, C::PercentOfBasic, null, '12.5')]);

        $this->assertSame('1000000', $this->amountOf($r, 'PCT'));
        $this->assertSame('9000000', $r->gross);
        $line = $r->lines[1];
        $this->assertSame('12.5', Money::trimRate($line->rate));
        $this->assertSame('8000000.00', $line->baseAmount);
        $this->assertSame('12.5% of 8,000,000', $line->description);
    }

    public function test_percent_of_gross_deduction_uses_full_gross(): void
    {
        $r = $this->compute([
            $this->basic('8000000'),
            $this->c('POS', K::Earning, C::Fixed, '2000000'),
            $this->c('PG', K::Deduction, C::PercentOfGross, null, '2'),
        ]);

        $this->assertSame('200000', $this->amountOf($r, 'PG'));
        $this->assertSame('9800000', $r->net);
    }

    public function test_percent_of_gross_is_ignored_for_earnings(): void
    {
        $r = $this->compute([$this->basic(), $this->c('BAD', K::Earning, C::PercentOfGross, null, '10')]);

        $this->assertNull($this->amountOf($r, 'BAD'));
        $this->assertContains('INVALID_EARNING_CALCULATION:BAD', $r->warnings);
    }

    public function test_per_present_day(): void
    {
        $r = $this->compute([$this->basic(), $this->c('TRN', K::Earning, C::PerPresentDay, '50000')], ['presentDays' => 21]);

        $this->assertSame('1050000', $this->amountOf($r, 'TRN'));
        $this->assertSame('21 days x 50,000', $r->lines[1]->description);
        $this->assertSame('21.00', $r->lines[1]->quantity);
    }

    public function test_per_overtime_hour_uses_exact_minutes(): void
    {
        $r = $this->compute([$this->basic(), $this->c('OT', K::Earning, C::PerOvertimeHour, '50000')], ['overtimeMinutes' => 90]);

        $this->assertSame('75000', $this->amountOf($r, 'OT'));
        $this->assertSame('1.50', $r->lines[1]->quantity);
    }

    public function test_unpaid_leave_deduction_is_basic_over_working_days_times_days(): void
    {
        $r = $this->compute(
            [$this->basic('8800000'), $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction)],
            ['workingDays' => 22, 'unpaidLeaveDays' => '1.5'],
        );

        $this->assertSame('600000', $this->amountOf($r, 'UL')); // 8,800,000 / 22 * 1.5
        $this->assertSame('8200000', $r->net);
    }

    public function test_unpaid_leave_deduction_rate_scales_the_daily_rate(): void
    {
        $r = $this->compute(
            [$this->basic('8800000'), $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction, null, '50')],
            ['workingDays' => 22, 'unpaidLeaveDays' => '2'],
        );

        $this->assertSame('400000', $this->amountOf($r, 'UL'));
    }

    public function test_unpaid_leave_without_working_days_is_flagged_not_divided_by_zero(): void
    {
        $r = $this->compute([$this->basic(), $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction)], ['workingDays' => 0, 'unpaidLeaveDays' => '1']);

        $this->assertNull($this->amountOf($r, 'UL'));
        $this->assertContains('NO_WORKING_DAYS', $r->warnings);
    }

    public function test_zero_amount_lines_are_omitted(): void
    {
        $r = $this->compute([
            $this->basic(),
            $this->c('TRN', K::Earning, C::PerPresentDay, '50000'),
            $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction),
        ], ['workingDays' => 22]);

        $this->assertCount(1, $r->lines);
    }

    public function test_rounding_half_up_per_line_with_zero_decimals(): void
    {
        // 7,000,000 / 21 * 1 = 333,333.33 -> 333,333 ; 1,000,001 * 2.5% = 25,000.025 -> 25,000
        $r = $this->compute([
            $this->basic('7000000'),
            $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction),
        ], ['workingDays' => 21, 'unpaidLeaveDays' => '1']);
        $this->assertSame('333333', $this->amountOf($r, 'UL'));
        $this->assertSame('6666667', $r->net);

        // exact half rounds up: 50,001 * 50% = 25,000.5 -> 25,001
        $half = $this->compute([$this->basic('50001'), $this->c('H', K::Deduction, C::PercentOfBasic, null, '50')]);
        $this->assertSame('25001', $this->amountOf($half, 'H'));
    }

    public function test_rounding_with_two_decimals(): void
    {
        $r = $this->compute([
            $this->basic('3000.00'),
            $this->c('PCT', K::Earning, C::PercentOfBasic, null, '3.3333'),
        ], ['decimals' => 2]);

        $this->assertSame('99.999', bcadd('99.999', '0', 3));
        $this->assertSame('100.00', $this->amountOf($r, 'PCT')); // 99.999 -> 100.00
        $this->assertSame('3100.00', $r->gross);
    }

    public function test_totals_always_equal_sum_of_rounded_lines(): void
    {
        $r = $this->compute([
            $this->basic('7333333'),
            $this->c('A', K::Earning, C::PercentOfBasic, null, '3.3333'),
            $this->c('B', K::Deduction, C::PercentOfGross, null, '1.1111'),
            $this->c('UL', K::Deduction, C::UnpaidLeaveDeduction),
        ], ['workingDays' => 23, 'unpaidLeaveDays' => '0.5']);

        $earn = Money::add(...array_map(fn ($l) => $l->amount, $r->earnings()));
        $ded = Money::add(...array_map(fn ($l) => $l->amount, $r->deductionLines()));
        $this->assertSame(0, Money::cmp($earn, $r->gross));
        $this->assertSame(0, Money::cmp($ded, $r->deductions));
        $this->assertSame(0, Money::cmp(Money::sub($r->gross, $r->deductions), $r->net));
    }

    public function test_negative_net_is_returned_and_flagged_not_clamped(): void
    {
        $r = $this->compute([$this->basic('1000000'), $this->c('LOAN', K::Deduction, C::Fixed, '1500000')]);

        $this->assertSame('-500000', $r->net);
        $this->assertTrue($r->isNegative());
        $this->assertContains('NEGATIVE_NET', $r->warnings);
    }

    public function test_missing_basic_is_warned(): void
    {
        $r = $this->compute([$this->c('TRN', K::Earning, C::Fixed, '100000')]);

        $this->assertContains('NO_BASIC_SALARY', $r->warnings);
        $this->assertSame('0', $r->basic);
    }

    public function test_manual_adjustment_entries(): void
    {
        $r = $this->compute([], ['manualEntries' => [
            new ManualEntry(K::Earning, 'Back pay July raise', '1000000', 'Raise effective July', 7),
            new ManualEntry(K::Deduction, 'Meal overpaid', '35000', 'Duplicate meal day', 8),
        ]]);

        $this->assertSame('1000000', $r->gross);
        $this->assertSame('965000', $r->net);
        $this->assertSame('MANUAL_ADJUSTMENT', $r->lines[0]->calculation);
        $this->assertSame(7, $r->lines[0]->adjustmentId);
        $this->assertSame([], $r->warnings);
    }

    public function test_basic_is_always_first_line_and_sort_order_is_respected(): void
    {
        $r = $this->compute([
            $this->c('Z', K::Earning, C::Fixed, '1', null, 1),
            $this->c('A', K::Earning, C::Fixed, '1', null, 5),
            $this->basic(),
        ]);

        $this->assertSame(['BASIC', 'Z', 'A'], array_map(fn ($l) => $l->code, $r->lines));
    }

    public function test_money_helpers(): void
    {
        $this->assertSame('-3', Money::round('-2.5', 0));
        $this->assertSame('0', Money::round('-0.4', 0));
        $this->assertSame('1,234,567.80', Money::format('1234567.8', 2));
        $this->assertSame('-1,000', Money::format('-1000', 0));
    }
}
