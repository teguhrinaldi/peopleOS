<?php

namespace App\Services\Reimbursements;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Request numbers such as RB-202610-0001, unique per company and month, race-free.
 *
 * The counter row is bumped with a single UPDATE ... LAST_INSERT_ID(last_number + 1): the row lock serializes
 * concurrent creators until their transaction ends, and each gets a distinct value. Numbers are gapless because a
 * rolled-back creation rolls its increment back too. request_number is also UNIQUE as the final backstop.
 */
class ExpenseNumberGenerator
{
    public function __construct(private readonly CompanyContext $context) {}

    public function period(): string
    {
        return $this->context->now()->format('Ym');
    }

    /** Create the counter row if missing. Call OUTSIDE the creating transaction (no locks linger, no deadlocks). */
    public function ensure(?string $period = null): void
    {
        $period ??= $this->period();
        DB::statement(
            'INSERT IGNORE INTO expense_number_sequences (company_id, period, last_number, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())',
            [$this->context->id(), $period],
        );
    }

    /** Call inside the transaction that inserts the request. */
    public function next(?string $period = null): string
    {
        $period ??= $this->period();

        $touched = $this->bump($period);
        if ($touched === 0) {
            $this->ensure($period);
            $this->bump($period);
        }

        $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return 'RB-'.$period.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function bump(string $period): int
    {
        return DB::update(
            'UPDATE expense_number_sequences SET last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW() WHERE company_id = ? AND period = ?',
            [$this->context->id(), $period],
        );
    }
}
