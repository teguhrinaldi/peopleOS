<?php

namespace Tests\Unit;

use App\Enums\DocumentStatus;
use App\Services\Documents\DocumentStatusCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentStatusCalculatorTest extends TestCase
{
    private DocumentStatusCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new DocumentStatusCalculator;
    }

    /** @return array<string, array{0: ?string, 1: DocumentStatus}> */
    public static function boundaries(): array
    {
        // today = 2026-09-30, N = 30
        return [
            'no expiry' => [null, DocumentStatus::Active],
            'expired yesterday' => ['2026-09-29', DocumentStatus::Expired],
            'long expired' => ['2020-01-01', DocumentStatus::Expired],
            'expires today (last valid day)' => ['2026-09-30', DocumentStatus::Expiring],
            'expires tomorrow' => ['2026-10-01', DocumentStatus::Expiring],
            'exactly N days left' => ['2026-10-30', DocumentStatus::Expiring],
            'N + 1 days left' => ['2026-10-31', DocumentStatus::Active],
            'far future' => ['2030-01-01', DocumentStatus::Active],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_derived_status_boundaries(?string $expiry, DocumentStatus $expected): void
    {
        $this->assertSame($expected, $this->calc->derive($expiry, '2026-09-30', 30));
    }

    public function test_archived_wins_over_every_derived_state(): void
    {
        foreach ([null, '2020-01-01', '2026-10-01', '2030-01-01'] as $expiry) {
            $this->assertSame(DocumentStatus::Archived, $this->calc->resolve(true, $expiry, '2026-09-30', 30));
        }
        $this->assertSame(DocumentStatus::Expired, $this->calc->resolve(false, '2020-01-01', '2026-09-30', 30));
    }

    public function test_expiring_window_follows_the_setting(): void
    {
        $this->assertSame(DocumentStatus::Active, $this->calc->derive('2026-10-10', '2026-09-30', 7));
        $this->assertSame(DocumentStatus::Expiring, $this->calc->derive('2026-10-07', '2026-09-30', 7));
        // N = 0: only the last valid day counts as expiring
        $this->assertSame(DocumentStatus::Expiring, $this->calc->derive('2026-09-30', '2026-09-30', 0));
        $this->assertSame(DocumentStatus::Active, $this->calc->derive('2026-10-01', '2026-09-30', 0));
    }

    public function test_days_until_expiry_across_month_and_leap_day(): void
    {
        $this->assertSame(0, $this->calc->daysUntilExpiry('2026-09-30', '2026-09-30'));
        $this->assertSame(-1, $this->calc->daysUntilExpiry('2026-09-29', '2026-09-30'));
        $this->assertSame(31, $this->calc->daysUntilExpiry('2026-10-31', '2026-09-30'));
        $this->assertSame(2, $this->calc->daysUntilExpiry('2028-03-01', '2028-02-28'));
    }

    public function test_transitions(): void
    {
        $c = $this->calc;
        $this->assertTrue($c->canTransition(DocumentStatus::Active, DocumentStatus::Expiring, false));
        $this->assertTrue($c->canTransition(DocumentStatus::Expiring, DocumentStatus::Expired, false));
        $this->assertTrue($c->canTransition(DocumentStatus::Expired, DocumentStatus::Active, false), 'renewed expiry date');
        $this->assertFalse($c->canTransition(DocumentStatus::Archived, DocumentStatus::Expired, false), 'the job never un-archives');
        $this->assertFalse($c->canTransition(DocumentStatus::Active, DocumentStatus::Archived, false), 'the job never archives');
        $this->assertTrue($c->canTransition(DocumentStatus::Active, DocumentStatus::Archived, true));
        $this->assertTrue($c->canTransition(DocumentStatus::Archived, DocumentStatus::Expired, true), 'restore to derived state');
        $this->assertFalse($c->canTransition(DocumentStatus::Archived, DocumentStatus::Archived, true));
    }
}
