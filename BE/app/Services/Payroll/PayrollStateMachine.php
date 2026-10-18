<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus as S;
use App\Exceptions\ApiException;

/**
 * THE payroll transition map. Every status change of a run (and its mirrored period) goes through
 * transition() - called only by PayrollTransitions::apply(). Anything not listed is refused with
 * 409 INVALID_STATE_TRANSITION.
 *
 *   DRAFT ─generate─► PROCESSING ─finish─► DRAFT ─submit─► REVIEW ─approve─► APPROVED ─finalize─► FINALIZED ─mark_paid─► PAID
 *
 * Explicit back-edges:
 *   REVIEW   ─generate─► PROCESSING          regenerate; it lands in DRAFT again and must be re-submitted
 *   REVIEW | APPROVED ─return_to_draft─► DRAFT   returned / approval withdrawn (reason required)
 *   PROCESSING ─abort_generation─► DRAFT | REVIEW  a failed or stalled generation restores the state it started from
 * Cancellation: DRAFT | REVIEW | APPROVED ─cancel─► CANCELLED.
 * Nothing leaves FINALIZED except mark_paid; PAID and CANCELLED are terminal.
 */
final class PayrollStateMachine
{
    /** action => [allowed from-states, to-state (null = restore target given by the caller)] */
    public const ACTIONS = [
        'generate' => [['DRAFT', 'REVIEW'], 'PROCESSING'],
        'finish_generation' => [['PROCESSING'], 'DRAFT'],
        'abort_generation' => [['PROCESSING'], null],
        'submit' => [['DRAFT'], 'REVIEW'],
        'approve' => [['REVIEW'], 'APPROVED'],
        'return_to_draft' => [['REVIEW', 'APPROVED'], 'DRAFT'],
        'finalize' => [['APPROVED'], 'FINALIZED'],
        'mark_paid' => [['FINALIZED'], 'PAID'],
        'cancel' => [['DRAFT', 'REVIEW', 'APPROVED'], 'CANCELLED'],
    ];

    /** States a failed/stalled generation may restore. */
    private const RESTORABLE = ['DRAFT', 'REVIEW'];

    /** Validate $action from $from and return the new state. */
    public static function transition(string $action, S $from, ?S $restoreTo = null): S
    {
        if (! isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException("Unknown payroll action [{$action}].");
        }
        [$sources, $target] = self::ACTIONS[$action];

        if (! in_array($from->value, $sources, true)) {
            throw ApiException::make(
                'INVALID_STATE_TRANSITION',
                sprintf('Payroll in %s cannot %s.', $from->value, str_replace('_', ' ', $action)),
                409,
                ['action' => $action, 'status' => $from->value, 'allowed_from' => $sources],
            );
        }

        if ($target === null) {
            if (! $restoreTo || ! in_array($restoreTo->value, self::RESTORABLE, true)) {
                throw new \LogicException('abort_generation must restore DRAFT or REVIEW.');
            }

            return $restoreTo;
        }

        return S::from($target);
    }

    /** Is there any action that moves $from to $to? (the full edge set, used by tests and docs) */
    public static function allows(S $from, S $to): bool
    {
        foreach (self::ACTIONS as [$sources, $target]) {
            if (! in_array($from->value, $sources, true)) {
                continue;
            }
            if ($target === $to->value || ($target === null && in_array($to->value, self::RESTORABLE, true))) {
                return true;
            }
        }

        return false;
    }

    /** User-facing actions available in a state (for API clients to render buttons). @return list<string> */
    public static function availableActions(S $from): array
    {
        $internal = ['finish_generation', 'abort_generation'];
        $out = [];
        foreach (self::ACTIONS as $action => [$sources]) {
            if (! in_array($action, $internal, true) && in_array($from->value, $sources, true)) {
                $out[] = $action;
            }
        }

        return $out;
    }
}
