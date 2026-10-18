<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LeaveRequestStoreRequest;
use App\Http\Requests\Api\ReviewRequest;
use App\Http\Resources\LeaveResources;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AccessScope;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveCalendar;
use App\Services\Leave\LeaveService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends ApiController
{
    private const WITH = ['employee', 'leaveType', 'reviewer'];

    public function __construct(
        private readonly LeaveService $leave,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveCalendar $calendar,
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
    ) {}

    /** Active leave types with the policy that applies to the caller. */
    public function types(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $types = LeaveType::query()->where('company_id', $this->context->id())->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return $this->ok($types->map(function (LeaveType $t) use ($employee) {
            $policy = null;
            if ($employee) {
                try {
                    $policy = $this->balances->policyFor($employee, $t);
                } catch (ApiException) {
                    $policy = null;
                }
            }

            return LeaveResources::type($t, $policy);
        })->values());
    }

    /**
     * Balances for a leave year. Own by default; `employee_id` is honoured only within the caller's
     * leave.view / leave.view_team scope (anything else is 403, never someone else's data).
     */
    public function balances(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'employee_id' => ['nullable', 'integer']]);

        $employee = $this->targetEmployee($request);
        $year = $request->integer('year') ?: $this->calendar->yearOf($this->context->today()->toDateString());

        $types = LeaveType::query()->where('company_id', $this->context->id())->where('is_active', true)->orderBy('sort_order')->get();
        foreach ($types as $type) {
            try {
                $this->balances->balanceFor($employee, $type, $year);
            } catch (ApiException) {
                // a type without an applicable policy has no balance for this employee
            }
        }

        $rows = LeaveBalance::query()->with('leaveType')->where('employee_id', $employee->id)->where('year', $year)->get()
            ->sortBy(fn ($b) => [$b->leaveType->sort_order, $b->leaveType->name])->values();

        return $this->ok($rows->map(fn ($b) => LeaveResources::balance($b)), 200, ['year' => $year, 'employee_id' => $employee->id]);
    }

    /** scope=mine (default) | review (what the caller may decide) | all (leave.view / leave.view_team) */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,review,all'],
            'status' => ['nullable', 'in:DRAFT,PENDING,APPROVED,REJECTED,CANCELLED'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'leave_type_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $query = LeaveRequest::query()->with(self::WITH);

        switch ($request->query('scope', 'mine')) {
            case 'review':
                if (! $user->hasAnyPermission('leave.approve', 'leave.approve_team')) {
                    throw ApiException::make('FORBIDDEN', 'You cannot review leave requests.', 403);
                }
                $query->whereIn('employee_id', $this->scope->employees($user, 'leave.approve', 'leave.approve_team', false)->select('employees.id'))
                    ->where('status', '!=', 'DRAFT');
                if ($me = $user->employee) {
                    $query->where('employee_id', '!=', $me->id);
                }
                break;
            case 'all':
                if (! $user->hasAnyPermission('leave.view', 'leave.view_team')) {
                    throw ApiException::make('FORBIDDEN', 'You cannot view other people\'s leave.', 403);
                }
                $query->whereIn('employee_id', $this->scope->employees($user, 'leave.view', 'leave.view_team', false)->select('employees.id'))
                    ->where('status', '!=', 'DRAFT'); // drafts are private to their author
                break;
            default:
                $query->where('employee_id', $this->employee($request)->id);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->integer('leave_type_id'));
        }
        if ($request->filled('from')) {
            $query->where('end_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('start_date', '<=', $request->query('to'));
        }

        return $this->paginated($query->orderByDesc('start_date')->orderByDesc('id')->paginate($this->perPage($request)), fn ($r) => LeaveResources::request($r));
    }

    public function preview(LeaveRequestStoreRequest $request): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->leaveData();
        $type = LeaveType::query()->where('company_id', $this->context->id())->where('is_active', true)->find($data['leave_type_id'])
            ?? throw ApiException::make('LEAVE_TYPE_UNAVAILABLE', 'This leave type is not available.', 422);

        $result = $this->leave->preview($employee, $type, $data['start_date'], $data['end_date'], $data['half_day'] ?? null);

        return $this->ok([
            'days' => $result['days'],
            'working_dates' => $result['working_dates'],
            'balance' => LeaveResources::balance($result['balance']->load('leaveType')),
            'available_after' => $result['available_after'],
        ]);
    }

    public function store(LeaveRequestStoreRequest $request): JsonResponse
    {
        $created = $this->leave->create($this->employee($request), $request->user(), $request->leaveData(), $request->boolean('submit', true));

        return $this->ok(LeaveResources::request($created->load(self::WITH)), 201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);
        if ($leaveRequest->status->value === 'DRAFT' && ! $this->scope->isSelf($request->user(), $leaveRequest->employee_id)) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $this->ok(LeaveResources::request($leaveRequest->load(self::WITH)) + [
            'working_dates' => $leaveRequest->dayRows()->pluck('date')->map->toDateString()->unique()->values(),
        ]);
    }

    public function update(LeaveRequestStoreRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->ok(LeaveResources::request($this->leave->updateDraft($request->user(), $leaveRequest, $request->leaveData())->load(self::WITH)));
    }

    public function submit(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->ok(LeaveResources::request($this->leave->submit($request->user(), $leaveRequest)->load(self::WITH)));
    }

    public function approve(ReviewRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->ok(LeaveResources::request($this->leave->approve($request->user(), $leaveRequest, $request->input('note'))->load(self::WITH)));
    }

    public function reject(ReviewRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->ok(LeaveResources::request($this->leave->reject($request->user(), $leaveRequest, (string) $request->input('note'))->load(self::WITH)));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->ok(LeaveResources::request($this->leave->cancel($request->user(), $leaveRequest, $request->input('reason'))->load(self::WITH)));
    }

    private function targetEmployee(Request $request): Employee
    {
        if (! $request->filled('employee_id')) {
            return $this->employee($request);
        }

        $id = $request->integer('employee_id');
        if (! $this->scope->canAccess($request->user(), $id, 'leave.view', 'leave.view_team')) {
            throw ApiException::make('FORBIDDEN', 'You cannot view this employee\'s leave.', 403);
        }

        return Employee::query()->findOrFail($id);
    }
}
