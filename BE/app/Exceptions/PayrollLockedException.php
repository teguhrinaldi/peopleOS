<?php

namespace App\Exceptions;

/**
 * Thrown by the payroll model guards when anything tries to change or delete finalized (or paid) payroll
 * through Eloquent. Rendered like any ApiException: 409 PAYROLL_LOCKED.
 */
class PayrollLockedException extends ApiException
{
    public static function for(string $what): self
    {
        return new self(
            'PAYROLL_LOCKED',
            "Finalized payroll cannot be changed or deleted ({$what}). Record corrections in an adjustment period.",
            409,
        );
    }
}
