<?php

namespace Tests\Unit;

use App\Services\Overtime\OvertimeCalculator;
use App\Services\Overtime\OvertimeRuleConfig;
use App\Services\Overtime\OvertimeVerifier;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** DB-free: the overtime rule engine maths and the attendance verification maths. Demo numbers, not statutory. */
class OvertimeCalculatorTest extends TestCase
{
    private OvertimeCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new OvertimeCalculator;
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = [], ?array $tiers = null): OvertimeRuleConfig
    {
        $tiers ??= [
            'workday' => [['up_to_hours' => 1, 'multiplier' => '1.5'], ['multiplier' => '2']],
            'rest_day' => [['up_to_hours' => 8, 'multiplier' => '2'], ['up_to_hours' => 9, 'multiplier' => '3'], ['multiplier' => '4']],
            'holiday' => [['multiplier' => '3']],
        ];

        return new OvertimeRuleConfig(...array_merge(['tiers' => OvertimeRuleConfig::tiersFromJson($tiers)], $overrides));
    }

    public function test_hourly_rate_is_basis_over_divisor_rounded_to_cents(): void
    {
        $this->assertSame('30000.00', $this->calc->hourlyRate('5190000', $this->rule()));
        $this->assertSame('46242.77', $this->calc->hourlyRate('8000000', $this->rule()));
        $this->assertSame('50000.00', $this->calc->hourlyRate('8000000', $this->rule(['rateDivisor' => '160'])));
    }

    public function test_workday_first_hour_and_following_hours_use_their_bands(): void
    {
        $r = $this->calc->calculate(180, 'workday', '30000.00', $this->rule(), 0);

        $this->assertSame(180, $r->payableMinutes);
        $this->assertSame([
            ['minutes' => 60, 'multiplier' => '1.5', 'amount' => '45000'],
            ['minutes' => 120, 'multiplier' => '2', 'amount' => '120000'],
        ], $r->segments);
        $this->assertSame('165000', $r->amount);
        $this->assertNull($r->multiplier());
    }

    public function test_bands_continue_from_minutes_already_paid_on_the_same_day(): void
    {
        $r = $this->calc->calculate(120, 'workday', '30000.00', $this->rule(), 0, minutesAlreadyOnDay: 60);

        $this->assertSame([['minutes' => 120, 'multiplier' => '2', 'amount' => '120000']], $r->segments);
        $this->assertSame('2', $r->multiplier());
    }

    public function test_rest_day_and_holiday_tiers(): void
    {
        $rest = $this->calc->calculate(600, 'rest_day', '30000.00', $this->rule(), 0);
        $this->assertSame(['480000', '90000', '120000'], array_column($rest->segments, 'amount'));
        $this->assertSame('690000', $rest->amount);

        $holiday = $this->calc->calculate(120, 'holiday', '30000.00', $this->rule(), 0);
        $this->assertSame('180000', $holiday->amount);
        $this->assertSame('3', $holiday->multiplier());
    }

    public function test_currency_decimals_round_each_segment_half_up(): void
    {
        // 46,242.77 x 100 / 60 x 1.5 = 115,606.925
        $tiers = ['workday' => [['multiplier' => '1.5']]];
        $this->assertSame('115607', $this->calc->calculate(100, 'workday', '46242.77', $this->rule([], $tiers), 0)->amount);
        $this->assertSame('115606.93', $this->calc->calculate(100, 'workday', '46242.77', $this->rule([], $tiers), 2)->amount);
    }

    public function test_rounding_to_quarter_hours_down_nearest_and_up(): void
    {
        $down = $this->rule(['roundingMinutes' => 15]);
        $nearest = $this->rule(['roundingMinutes' => 15, 'roundingDirection' => 'NEAREST']);
        $up = $this->rule(['roundingMinutes' => 15, 'roundingDirection' => 'UP']);

        $this->assertSame(45, $this->calc->roundMinutes(59, $down));
        $this->assertSame(45, $this->calc->roundMinutes(52, $nearest));
        $this->assertSame(60, $this->calc->roundMinutes(53, $nearest));
        $this->assertSame(60, $this->calc->roundMinutes(46, $up));
        $this->assertSame(47, $this->calc->roundMinutes(47, $this->rule()));

        $r = $this->calc->calculate(59, 'workday', '30000.00', $down, 0);
        $this->assertSame(45, $r->payableMinutes);
        $this->assertSame('33750', $r->amount); // 30,000 x 0.75 h x 1.5
        $this->assertContains('ROUNDED', $r->notes);
    }

    public function test_minimum_per_request(): void
    {
        $r = $this->calc->calculate(30, 'workday', '30000.00', $this->rule(['minMinutesPerRequest' => 60]), 0);

        $this->assertSame(0, $r->payableMinutes);
        $this->assertSame('0', $r->amount);
        $this->assertSame(['BELOW_MINIMUM'], $r->notes);
    }

    public function test_day_and_period_caps(): void
    {
        $day = $this->calc->calculate(120, 'workday', '30000.00', $this->rule(['maxMinutesPerDay' => 240]), 0, minutesAlreadyOnDay: 200);
        $this->assertSame(40, $day->payableMinutes);
        $this->assertSame('40000', $day->amount); // 40 min at x2 (beyond the first hour)
        $this->assertContains('CAPPED_DAY', $day->notes);

        $period = $this->calc->calculate(180, 'workday', '30000.00', $this->rule(['maxMinutesPerPeriod' => 600]), 0, minutesAlreadyInPeriod: 540);
        $this->assertSame(60, $period->payableMinutes);
        $this->assertSame('45000', $period->amount);
        $this->assertContains('CAPPED_PERIOD', $period->notes);

        $none = $this->calc->calculate(60, 'workday', '30000.00', $this->rule(['maxMinutesPerPeriod' => 600]), 0, minutesAlreadyInPeriod: 600);
        $this->assertSame(0, $none->payableMinutes);
    }

    public function test_missing_day_type_and_bounded_last_band(): void
    {
        $noHoliday = $this->rule([], ['workday' => [['multiplier' => '1.5']]]);
        $r = $this->calc->calculate(60, 'holiday', '30000.00', $noHoliday, 0);
        $this->assertSame(0, $r->payableMinutes);
        $this->assertSame(['NO_TIER_FOR_DAY_TYPE'], $r->notes);

        $bounded = $this->rule([], ['holiday' => [['up_to_hours' => 2, 'multiplier' => '3']]]);
        $b = $this->calc->calculate(180, 'holiday', '30000.00', $bounded, 0);
        $this->assertSame(120, $b->payableMinutes);
        $this->assertSame('180000', $b->amount);
        $this->assertContains('BEYOND_LAST_BAND', $b->notes);
    }

    public function test_invalid_tiers_are_rejected(): void
    {
        foreach ([
            ['weekend' => [['multiplier' => '2']]],
            ['workday' => [['multiplier' => '0']]],
            ['workday' => [['up_to_hours' => 2, 'multiplier' => '1.5'], ['up_to_hours' => 1, 'multiplier' => '2']]],
            ['workday' => [['multiplier' => '1.5'], ['multiplier' => '2']]],
        ] as $tiers) {
            try {
                OvertimeRuleConfig::tiersFromJson($tiers);
                $this->fail('accepted invalid tiers '.json_encode($tiers));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_verified_minutes_are_the_approved_window_inside_attendance_outside_the_shift(): void
    {
        $v = new OvertimeVerifier;
        $t = fn (string $s) => CarbonImmutable::parse("2026-07-14 {$s}", 'UTC');
        // Shift 08:00-17:00 Jakarta = 01:00-10:00 UTC. Overtime 10:00-13:00 UTC approved 180.
        $this->assertSame(120, $v->verifiedMinutes(180, $t('10:00'), $t('00:55'), $t('12:00'), $t('01:00'), $t('10:00')));
        $this->assertSame(180, $v->verifiedMinutes(180, $t('10:00'), $t('00:55'), $t('13:30'), $t('01:00'), $t('10:00')));
        // Approved less than requested: only the approved part of the window counts.
        $this->assertSame(90, $v->verifiedMinutes(90, $t('10:00'), $t('00:55'), $t('13:30'), $t('01:00'), $t('10:00')));
        // Window overlapping the regular shift: shift time is not overtime.
        $this->assertSame(60, $v->verifiedMinutes(120, $t('09:00'), $t('00:55'), $t('11:00'), $t('01:00'), $t('10:00')));
        // Rest day: no regular shift, the whole overlap counts.
        $this->assertSame(180, $v->verifiedMinutes(240, $t('02:00'), $t('01:30'), $t('05:00')));
        // Left before the overtime started.
        $this->assertSame(0, $v->verifiedMinutes(120, $t('10:00'), $t('00:55'), $t('09:50'), $t('01:00'), $t('10:00')));

        $this->assertSame('VERIFIED', $v->status(120, 120));
        $this->assertSame('PARTIAL', $v->status(120, 30));
        $this->assertSame('NOT_VERIFIED', $v->status(120, 0));
    }
}
