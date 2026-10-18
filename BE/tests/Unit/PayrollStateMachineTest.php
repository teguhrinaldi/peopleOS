<?php

namespace Tests\Unit;

use App\Enums\PayrollStatus as S;
use App\Exceptions\ApiException;
use App\Services\Payroll\PayrollStateMachine;
use PHPUnit\Framework\TestCase;

/** Exhaustive: every (state, state) pair and every (action, state) pair. */
class PayrollStateMachineTest extends TestCase
{
    /** The documented edge set; any change to the machine must change this table deliberately. */
    private const EDGES = [
        'DRAFT' => ['PROCESSING', 'REVIEW', 'CANCELLED'],
        'PROCESSING' => ['DRAFT', 'REVIEW'],
        'REVIEW' => ['PROCESSING', 'DRAFT', 'APPROVED', 'CANCELLED'],
        'APPROVED' => ['FINALIZED', 'DRAFT', 'CANCELLED'],
        'FINALIZED' => ['PAID'],
        'PAID' => [],
        'CANCELLED' => [],
    ];

    public function test_every_state_pair_matches_the_documented_edges(): void
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $expected = in_array($to->value, self::EDGES[$from->value], true);
                $this->assertSame($expected, PayrollStateMachine::allows($from, $to), "{$from->value} -> {$to->value}");
            }
        }
    }

    public function test_every_action_in_every_state_is_allowed_or_409(): void
    {
        foreach (PayrollStateMachine::ACTIONS as $action => [$sources, $target]) {
            foreach (S::cases() as $from) {
                $allowed = in_array($from->value, $sources, true);
                try {
                    $to = PayrollStateMachine::transition($action, $from, S::Draft);
                    $this->assertTrue($allowed, "{$action} from {$from->value} should be refused");
                    $this->assertSame($target ?? 'DRAFT', $to->value);
                } catch (ApiException $e) {
                    $this->assertFalse($allowed, "{$action} from {$from->value} should be allowed");
                    $this->assertSame('INVALID_STATE_TRANSITION', $e->errorCode);
                    $this->assertSame(409, $e->status);
                }
            }
        }
    }

    public function test_nothing_leaves_finalized_except_paid_and_terminals_are_terminal(): void
    {
        $this->assertSame(['mark_paid'], PayrollStateMachine::availableActions(S::Finalized));
        $this->assertSame([], PayrollStateMachine::availableActions(S::Paid));
        $this->assertSame([], PayrollStateMachine::availableActions(S::Cancelled));
        $this->assertSame([], PayrollStateMachine::availableActions(S::Processing));
    }

    public function test_abort_generation_may_only_restore_draft_or_review(): void
    {
        $this->assertSame(S::Review, PayrollStateMachine::transition('abort_generation', S::Processing, S::Review));
        $this->expectException(\LogicException::class);
        PayrollStateMachine::transition('abort_generation', S::Processing, S::Approved);
    }
}
