<?php

namespace App\Services\Reimbursements;

use App\Enums\ExpenseStatus as S;
use App\Exceptions\ApiException;

/** The ONE place that knows which status may follow which. Every service action calls assert() before changing a status. */
final class ExpenseStateMachine
{
    /** @return array<string, list<S>> */
    public static function transitions(): array
    {
        return [
            S::Draft->value => [S::Submitted, S::Cancelled],
            S::Submitted->value => [S::UnderReview, S::Cancelled],
            S::UnderReview->value => [S::Approved, S::Rejected, S::Cancelled],
            S::Approved->value => [S::ScheduledForPayment, S::Cancelled],
            S::ScheduledForPayment->value => [S::Paid, S::Cancelled],
            S::Paid->value => [],
            S::Rejected->value => [],
            S::Cancelled->value => [],
        ];
    }

    public static function can(S $from, S $to): bool
    {
        return in_array($to, self::transitions()[$from->value], true);
    }

    /** @return list<S> */
    public static function next(S $from): array
    {
        return self::transitions()[$from->value];
    }

    /** @throws ApiException 409 INVALID_TRANSITION */
    public static function assert(S $from, S $to): void
    {
        if (! self::can($from, $to)) {
            throw ApiException::make('INVALID_TRANSITION', "A reimbursement that is {$from->value} cannot become {$to->value}.", 409, [
                'status' => $from->value, 'requested' => $to->value,
            ]);
        }
    }
}
