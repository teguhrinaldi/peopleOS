<?php

namespace App\Services\Admin\Concerns;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Per-employee date-ranged overrides (shift assignments, working-calendar assignments).
 * An open end (null) means "until further notice". Past days are never rewritten:
 * a range that already started can only be ended, not deleted.
 *
 * Requires `$this->activity` (ActivityLogger) and `$this->context` (CompanyContext).
 */
trait ManagesDateRanges
{
    /** @param Builder<Model> $sameEmployee rows of the same employee (lock them first) */
    protected function assertNoOverlap(Builder $sameEmployee, string $from, ?string $to, string $startColumn, string $endColumn, string $field = 'start_date'): void
    {
        $overlaps = $sameEmployee
            ->when($to !== null, fn (Builder $q) => $q->where($startColumn, '<=', $to))
            ->where(fn (Builder $q) => $q->whereNull($endColumn)->orWhere($endColumn, '>=', $from))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([$field => 'This period overlaps another one for the same employee. End that one first.']);
        }
    }

    /**
     * Future range -> deleted. Running range -> ends today. Finished range -> refused (it is history).
     *
     * @return 'deleted'|'ended'
     */
    protected function endOrDelete(Model $range, string $startColumn, string $endColumn, string $entity, User $actor): string
    {
        $today = $this->context->today()->toDateString();
        $start = $range->{$startColumn}->toDateString();
        $end = $range->{$endColumn}?->toDateString();

        if ($start > $today) {
            $this->activity->log("{$entity}.deleted", $range, $range->only([$startColumn, $endColumn]), null, actor: $actor);
            $range->delete();

            return 'deleted';
        }

        if ($end !== null && $end <= $today) {
            throw ApiException::make('RANGE_ALREADY_ENDED', 'This period has already ended and is kept as history.', 409);
        }

        $range->{$endColumn} = $today;
        $range->save();
        $this->activity->log("{$entity}.ended", $range, [$endColumn => $end], [$endColumn => $today], actor: $actor);

        return 'ended';
    }
}
