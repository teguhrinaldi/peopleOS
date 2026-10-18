<?php

namespace App\Enums;

/** Workflow state of a payroll run (mirrored on its period). Transitions live in PayrollStateMachine only. */
enum PayrollStatus: string
{
    case Draft = 'DRAFT';
    case Processing = 'PROCESSING';
    case Review = 'REVIEW';
    case Approved = 'APPROVED';
    case Finalized = 'FINALIZED';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    /** Finalized payroll (and everything under it) is immutable. */
    public function isLocked(): bool
    {
        return $this === self::Finalized || $this === self::Paid;
    }

    /** Employees may see their payslip only once payroll reached one of these. */
    public function isReleased(): bool
    {
        return $this->isLocked();
    }
}
