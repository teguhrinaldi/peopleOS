<?php

namespace App\Enums;

/**
 * DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED | REJECTED ; APPROVED -> SCHEDULED_FOR_PAYMENT -> PAID ;
 * DRAFT/SUBMITTED/UNDER_REVIEW (and APPROVED/SCHEDULED for HR, before payment) -> CANCELLED.
 * The transition table lives in App\Services\Reimbursements\ExpenseStateMachine and nowhere else.
 */
enum ExpenseStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case ScheduledForPayment = 'SCHEDULED_FOR_PAYMENT';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Paid, self::Cancelled], true);
    }

    /** Statuses that count against monthly limits (money that is, or will be, spent). @return list<self> */
    public static function counted(): array
    {
        return [self::Submitted, self::UnderReview, self::Approved, self::ScheduledForPayment, self::Paid];
    }
}
