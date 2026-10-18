<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Validated, normalized filter values. Dates are company-local Y-m-d strings. */
final class ReportFilters
{
    /** Longest date window a report accepts (keeps queries and exports bounded). */
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $departmentId = null,
        public readonly ?int $employeeId = null,
        public readonly ?string $status = null,
        public readonly ?int $periodId = null,
        public readonly ?int $days = null,
        public readonly ?int $leaveTypeId = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, string $today): self
    {
        $to = isset($input['to']) && $input['to'] !== '' ? (string) $input['to'] : $today;
        $from = isset($input['from']) && $input['from'] !== ''
            ? (string) $input['from']
            : CarbonImmutable::parse($to)->startOfMonth()->toDateString();

        return new self(
            from: $from,
            to: $to,
            departmentId: self::int($input['department_id'] ?? null),
            employeeId: self::int($input['employee_id'] ?? null),
            status: isset($input['status']) && $input['status'] !== '' ? strtoupper((string) $input['status']) : null,
            periodId: self::int($input['period_id'] ?? null),
            days: self::int($input['days'] ?? null),
            leaveTypeId: self::int($input['leave_type_id'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from, 'to' => $this->to, 'department_id' => $this->departmentId, 'employee_id' => $this->employeeId,
            'status' => $this->status, 'period_id' => $this->periodId, 'days' => $this->days, 'leave_type_id' => $this->leaveTypeId,
        ], fn ($v) => $v !== null);
    }

    private static function int(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }
}
