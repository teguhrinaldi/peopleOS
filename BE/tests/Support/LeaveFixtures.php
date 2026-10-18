<?php

namespace Tests\Support;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveSetupService;

/** Leave types for tests: Annual (12 days, 0 notice for testability), Sick (unlimited, back-dating 7 days), Unpaid (unlimited). */
trait LeaveFixtures
{
    protected LeaveType $annual;

    protected LeaveType $sick;

    protected LeaveType $unpaid;

    protected function setUpLeave(): void
    {
        $setup = app(LeaveSetupService::class);
        $admin = User::query()->firstOrCreate(['email' => 'setup-admin@test.example'], ['name' => 'Setup', 'password' => 'password']);

        $this->annual = $setup->createType(
            ['code' => 'ANNUAL', 'name' => 'Annual Leave', 'is_paid' => true, 'color' => 'blue'],
            ['annual_allowance' => 12, 'prorate_first_year' => false, 'carry_over_max' => 5, 'allow_half_day' => true, 'requires_reason' => false],
            $admin,
        );
        $this->sick = $setup->createType(
            ['code' => 'SICK', 'name' => 'Sick Leave', 'is_paid' => true, 'color' => 'orange'],
            ['annual_allowance' => null, 'max_past_days' => 7, 'requires_reason' => true],
            $admin,
        );
        $this->unpaid = $setup->createType(
            ['code' => 'UNPAID', 'name' => 'Unpaid Leave', 'is_paid' => false, 'color' => 'gray'],
            ['annual_allowance' => null, 'requires_reason' => true],
            $admin,
        );
    }

    /** @return array<string, mixed> */
    protected function leavePayload(LeaveType $type, string $start, ?string $end = null, array $extra = []): array
    {
        return array_merge(['leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end ?? $start, 'reason' => 'Family matters'], $extra);
    }
}
