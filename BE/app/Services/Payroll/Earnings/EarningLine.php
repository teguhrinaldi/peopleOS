<?php

namespace App\Services\Payroll\Earnings;

use App\Enums\SalaryComponentKind;

/**
 * One payroll line proposed by an earning source. `refs` are the source records it pays (stored as JSON on the
 * line, e.g. [{type: "overtime_request", id: 12, work_date: "2026-07-14", minutes: 120, amount: "..."}]);
 * `payload` is private to the source (handed back to markIncluded, never stored as is).
 */
final class EarningLine
{
    /**
     * @param  list<array<string, mixed>>  $refs
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $code,
        public readonly string $name,
        public readonly string $amount,
        public readonly array $refs,
        public readonly SalaryComponentKind $kind = SalaryComponentKind::Earning,
        public readonly ?string $quantity = null,
        public readonly ?string $unitAmount = null,
        public readonly ?string $baseAmount = null,
        public readonly ?int $minutes = null,
        public readonly ?string $multiplier = null,
        public readonly ?string $description = null,
        public readonly int $sortOrder = 500,
        public readonly array $payload = [],
    ) {}
}
