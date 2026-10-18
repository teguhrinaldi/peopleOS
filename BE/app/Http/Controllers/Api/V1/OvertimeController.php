<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\OvertimeStoreRequest;
use App\Http\Requests\Api\ReviewRequest;
use App\Http\Resources\RequestResources;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Attendance\OvertimeService;
use App\Services\Overtime\OvertimePayrollService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends ApiController
{
    public function __construct(private readonly OvertimeService $overtime, private readonly AccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,review'],
            'status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,CANCELLED'],
            'payroll_status' => ['nullable', 'in:NONE,PENDING_PAYROLL,INCLUDED,ADJUSTED,LEGACY'],
        ]);

        $user = $request->user();
        $query = OvertimeRequest::query()->with('employee');

        if ($request->query('scope', 'mine') === 'review') {
            if (! $user->hasAnyPermission('overtime.approve', 'overtime.approve_team')) {
                throw ApiException::make('FORBIDDEN', 'You cannot review overtime requests.', 403);
            }
            $visible = $this->scope->employees($user, 'overtime.approve', 'overtime.approve_team', false)->select('employees.id');
            $query->whereIn('employee_id', $visible);
            if ($me = $user->employee) {
                $query->where('employee_id', '!=', $me->id);
            }
        } else {
            $query->where('employee_id', $this->employee($request)->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('payroll_status')) {
            $query->where('payroll_status', $request->query('payroll_status'));
        }

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)), fn ($o) => RequestResources::overtime($o, $this->paySafe($user, $o)));
    }

    public function store(OvertimeStoreRequest $request): JsonResponse
    {
        $overtime = $this->overtime->request(
            $this->employee($request),
            $request->user(),
            $request->string('work_date')->toString(),
            CarbonImmutable::parse($request->input('start_at'))->utc(),
            CarbonImmutable::parse($request->input('end_at'))->utc(),
            $request->string('reason')->toString(),
        );

        return $this->ok(RequestResources::overtime($overtime->load('employee')), 201);
    }

    public function show(OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('view', $overtimeRequest);

        return $this->ok(RequestResources::overtime($overtimeRequest->load('employee'), $this->paySafe(request()->user(), $overtimeRequest)));
    }

    public function approve(ReviewRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('approve', $overtimeRequest);

        return $this->ok(RequestResources::overtime(
            $this->overtime->approve($request->user(), $overtimeRequest, $request->integer('approved_minutes') ?: null, $request->input('note'))->load('employee'),
        ));
    }

    public function reject(ReviewRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('approve', $overtimeRequest);

        return $this->ok(RequestResources::overtime(
            $this->overtime->reject($request->user(), $overtimeRequest, (string) $request->input('note'))->load('employee'),
        ));
    }

    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('cancel', $overtimeRequest);

        return $this->ok(RequestResources::overtime($this->overtime->cancel($request->user(), $overtimeRequest)->load('employee')));
    }

    /** POST /overtime-requests/{id}/void-payroll-inclusion  (payroll.manage; finalized inclusions only) */
    public function voidInclusion(Request $request, OvertimeRequest $overtimeRequest, OvertimePayrollService $payroll): JsonResponse
    {
        $this->authorize('view', $overtimeRequest);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $payroll->void($request->user(), $overtimeRequest, (string) $request->input('reason'));

        return $this->ok(RequestResources::overtime($overtimeRequest->refresh()->load('employee'), true));
    }

    /** Pay figures and payslip references only for the employee themself or payroll / payslip viewers. */
    private function paySafe(?User $user, OvertimeRequest $o): bool
    {
        return $user !== null && (($user->employee?->id === $o->employee_id) || $user->hasAnyPermission('payroll.view', 'payslip.view'));
    }
}
