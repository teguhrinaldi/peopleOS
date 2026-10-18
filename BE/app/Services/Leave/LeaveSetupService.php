<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Exceptions\ApiException;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Configuration of leave types and their policies (permission: leave.manage). */
class LeaveSetupService
{
    private const TYPE_FIELDS = ['code', 'name', 'description', 'is_paid', 'color', 'is_active', 'sort_order'];

    private const POLICY_FIELDS = [
        'employment_type', 'annual_allowance', 'prorate_first_year', 'carry_over_max', 'allow_negative', 'max_negative_days',
        'allow_half_day', 'allow_overlap', 'min_notice_days', 'max_past_days', 'max_consecutive_days', 'requires_reason',
    ];

    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $type
     * @param  array<string, mixed>  $defaultPolicy  the policy that applies when no employment-type policy matches
     */
    public function createType(array $type, array $defaultPolicy, User $actor): LeaveType
    {
        return DB::transaction(function () use ($type, $defaultPolicy, $actor) {
            $model = LeaveType::query()->create(array_intersect_key($type, array_flip(self::TYPE_FIELDS)) + ['company_id' => $this->context->id()]);
            $model->policies()->create(array_intersect_key($defaultPolicy, array_flip(self::POLICY_FIELDS)) + ['employment_type' => null]);
            $this->activity->log('leave_type.created', $model, null, $model->only(self::TYPE_FIELDS), actor: $actor);

            return $model->load('policies');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateType(LeaveType $type, array $data, User $actor): LeaveType
    {
        $type->fill(array_intersect_key($data, array_flip(self::TYPE_FIELDS)));
        if ($type->isDirty()) {
            [$old, $new] = $this->activity->diff($type);
            $type->save();
            $this->activity->log('leave_type.updated', $type, $old, $new, actor: $actor);
        }

        return $type;
    }

    /**
     * Upsert the policy for one employment type (null = default). Existing balances keep their allowance
     * snapshot; the change applies to balances created afterwards and to future request validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePolicy(LeaveType $type, array $data, User $actor): LeavePolicy
    {
        $employmentType = $data['employment_type'] ?? null;
        $policy = $type->policies()->firstOrNew(['employment_type' => $employmentType]);
        $policy->fill(array_intersect_key($data, array_flip(self::POLICY_FIELDS)));

        [$old, $new] = $this->activity->diff($policy);
        $policy->save();
        $this->activity->log('leave_policy.saved', $policy, $policy->wasRecentlyCreated ? null : $old, $new, actor: $actor);

        return $policy;
    }

    public function deletePolicy(LeavePolicy $policy, User $actor): void
    {
        if ($policy->employment_type === null) {
            throw ApiException::make('LEAVE_POLICY_REQUIRED', 'The default policy of a leave type cannot be removed.', 409);
        }

        $this->activity->log('leave_policy.deleted', $policy, $policy->snapshot(), null, actor: $actor);
        $policy->delete();
    }

    /** Types in use are retired (inactive), never removed: history keeps pointing at them. */
    public function deleteType(LeaveType $type, User $actor): void
    {
        if (LeaveRequest::query()->where('leave_type_id', $type->id)->exists()) {
            throw ApiException::make('LEAVE_TYPE_IN_USE', 'This leave type has requests. Deactivate it instead.', 409);
        }

        $this->activity->log('leave_type.deleted', $type, $type->only(self::TYPE_FIELDS), null, actor: $actor);
        $type->delete();
    }

    public function pendingCount(LeaveType $type): int
    {
        return LeaveRequest::query()->where('leave_type_id', $type->id)->where('status', LeaveStatus::Pending->value)->count();
    }
}
