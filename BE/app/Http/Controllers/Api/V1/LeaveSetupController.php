<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LeaveResources;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\AccessScope;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveSetupService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Leave configuration and balance adjustments. Route middleware requires leave.manage. */
class LeaveSetupController extends ApiController
{
    public function __construct(
        private readonly LeaveSetupService $setup,
        private readonly LeaveBalanceService $balances,
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $types = LeaveType::query()->where('company_id', $this->context->id())->with('policies')->orderBy('sort_order')->orderBy('name')->get();

        return $this->ok($types->map(fn (LeaveType $t) => LeaveResources::type($t) + [
            'policies' => $t->policies->map(fn ($p) => LeaveResources::policy($p))->values(),
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->validate($this->typeRules(true));
        $policy = $request->validate(['policy' => ['required', 'array']] + $this->policyRules('policy.'))['policy'];

        return $this->ok(LeaveResources::type($this->setup->createType($type, $policy, $request->user())), 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->assertCompany($leaveType);
        $data = $request->validate($this->typeRules(false, $leaveType->id));

        return $this->ok(LeaveResources::type($this->setup->updateType($leaveType, $data, $request->user())));
    }

    public function savePolicy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->assertCompany($leaveType);
        $data = $request->validate(['employment_type' => ['nullable', 'in:permanent,contract,probation,intern,part_time']] + $this->policyRules(''));

        return $this->ok(LeaveResources::policy($this->setup->savePolicy($leaveType, $data, $request->user())));
    }

    public function adjustBalance(Request $request, LeaveBalance $leaveBalance): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'numeric', 'between:-365,365', 'multiple_of:0.5'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless($this->scope->canAccess($request->user(), $leaveBalance->employee_id, 'leave.manage'), 403);

        $balance = $this->balances->adjust($leaveBalance, number_format((float) $data['delta'], 1, '.', ''), $data['reason'], $request->user());

        return $this->ok(LeaveResources::balance($balance->load('leaveType')));
    }

    private function assertCompany(LeaveType $type): void
    {
        abort_unless($type->company_id === $this->context->id(), 404);
    }

    /** @return array<string, mixed> */
    private function typeRules(bool $creating, ?int $ignoreId = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $unique = 'unique:leave_types,code,'.($ignoreId ?? 'NULL').',id,company_id,'.$this->context->id();

        return [
            'code' => [$req, 'string', 'max:30', 'alpha_dash', $unique],
            'name' => [$req, 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_paid' => [$req, 'boolean'],
            'color' => ['nullable', 'in:blue,green,orange,red,purple,teal,gray'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function policyRules(string $prefix): array
    {
        return [
            $prefix.'annual_allowance' => ['nullable', 'numeric', 'min:0', 'max:365', 'multiple_of:0.5'],
            $prefix.'prorate_first_year' => ['sometimes', 'boolean'],
            $prefix.'carry_over_max' => ['sometimes', 'numeric', 'min:0', 'max:365', 'multiple_of:0.5'],
            $prefix.'allow_negative' => ['sometimes', 'boolean'],
            $prefix.'max_negative_days' => ['sometimes', 'numeric', 'min:0', 'max:60', 'multiple_of:0.5'],
            $prefix.'allow_half_day' => ['sometimes', 'boolean'],
            $prefix.'allow_overlap' => ['sometimes', 'boolean'],
            $prefix.'min_notice_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            $prefix.'max_past_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            $prefix.'max_consecutive_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            $prefix.'requires_reason' => ['sometimes', 'boolean'],
        ];
    }
}
