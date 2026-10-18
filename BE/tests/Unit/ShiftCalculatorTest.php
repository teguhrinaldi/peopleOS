<?php

namespace Tests\Unit;

use App\Services\Attendance\ShiftCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ShiftCalculatorTest extends TestCase
{
    private ShiftCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ShiftCalculator;
    }

    public function test_day_shift_does_not_cross_midnight(): void
    {
        $this->assertFalse($this->calc->crossesMidnight('08:00', '17:00'));
        $this->assertSame(540, $this->calc->spanMinutes('08:00', '17:00'));
    }

    public function test_overnight_shift_is_one_logical_span_across_midnight(): void
    {
        $this->assertTrue($this->calc->crossesMidnight('22:00', '06:00'));
        $this->assertSame(480, $this->calc->spanMinutes('22:00', '06:00'));
    }

    public function test_overnight_window_ends_on_the_next_day_in_utc(): void
    {
        [$start, $end] = $this->calc->window('2026-09-29', '22:00:00', '06:00:00', 'Asia/Jakarta');

        $this->assertSame('2026-09-29 15:00:00', $start->format('Y-m-d H:i:s')); // 22:00 WIB
        $this->assertSame('2026-09-29 23:00:00', $end->format('Y-m-d H:i:s'));   // 06:00 WIB next day
        $this->assertSame(480, $this->calc->minutesBetween($start, $end));
    }

    public function test_day_window_stays_on_the_same_local_day(): void
    {
        [$start, $end] = $this->calc->window('2026-09-30', '08:00', '17:00', 'Asia/Jakarta');

        $this->assertSame('2026-09-30T01:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-09-30T10:00:00+00:00', $end->toIso8601String());
    }

    public function test_working_minutes_subtract_unpaid_breaks_only(): void
    {
        $this->assertSame(480, $this->calc->workingMinutes('08:00', '17:00', [['duration_minutes' => 60, 'is_paid' => false]]));
        $this->assertSame(540, $this->calc->workingMinutes('08:00', '17:00', [['duration_minutes' => 60, 'is_paid' => true]]));
        $this->assertSame(420, $this->calc->workingMinutes('22:00', '06:00', [['duration_minutes' => 60, 'is_paid' => false]]));
    }

    public function test_grace_period_is_forgiving_but_once_exceeded_the_whole_delay_is_late(): void
    {
        $start = CarbonImmutable::parse('2026-09-30 01:00:00', 'UTC');

        $this->assertSame(0, $this->calc->lateMinutes($start, $start->addMinutes(10), 10)); // exactly at the grace edge
        $this->assertSame(11, $this->calc->lateMinutes($start, $start->addMinutes(11), 10));
        $this->assertSame(0, $this->calc->lateMinutes($start, $start->subMinutes(20), 10)); // early arrival
    }

    public function test_early_leave_respects_its_grace(): void
    {
        $end = CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC');

        $this->assertSame(0, $this->calc->earlyLeaveMinutes($end, $end->subMinutes(5), 10));
        $this->assertSame(30, $this->calc->earlyLeaveMinutes($end, $end->subMinutes(30), 10));
        $this->assertSame(0, $this->calc->earlyLeaveMinutes($end, $end->addMinutes(45), 10));
    }

    public function test_worked_minutes_deduct_the_break_and_never_go_negative(): void
    {
        $in = CarbonImmutable::parse('2026-09-30 01:00:00', 'UTC');

        $this->assertSame(480, $this->calc->workedMinutes($in, $in->addMinutes(540), 60));
        $this->assertSame(30, $this->calc->workedMinutes($in, $in->addMinutes(30), 60)); // shorter than the break: not deducted
        $this->assertSame(0, $this->calc->workedMinutes($in, $in->subMinutes(10), 60));
    }
}
