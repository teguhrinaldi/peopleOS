<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CorrectionStoreRequest;
use App\Http\Requests\Api\ReviewRequest;
use App\Http\Resources\RequestResources;
use App\Models\AttendanceCorrection;
use App\Services\AccessScope;
use App\Services\Attendance\CorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorrectionController extends ApiController
{
    public function __construct(private readonly CorrectionService $corrections, private readonly AccessScope $scope) {}

    /** scope=mine (default) | review (requests the caller may decide) */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,review'],
            'status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,CANCELLED'],
        ]);

        $user = $request->user();
        $query = AttendanceCorrection::query()->with('employee');

        if ($request->query('scope', 'mine') === 'review') {
            if (! $user->hasAnyPermission('attendance.correct', 'attendance.approve_team')) {
                throw ApiException::make('FORBIDDEN', 'You cannot review corrections.', 403);
            }
            $visible = $this->scope->employees($user, 'attendance.correct', 'attendance.approve_team', false)->select('employees.id');
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

        $page = $query->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginated($page, fn ($c) => RequestResources::correction($c));
    }

    public function store(CorrectionStoreRequest $request): JsonResponse
    {
        $employee = $this->employee($request);
        $parse = fn (?string $v) => $v ? CarbonImmutable::parse($v)->utc() : null;

        $correction = $this->corrections->request(
            $employee,
            $request->user(),
            $request->string('work_date')->toString(),
            $parse($request->input('requested_check_in_at')),
            $parse($request->input('requested_check_out_at')),
            $request->string('reason')->toString(),
        );

        return $this->ok(RequestResources::correction($correction->load('employee')), 201);
    }

    public function show(AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('view', $correction);

        return $this->ok(RequestResources::correction($correction->load('employee')));
    }

    public function approve(ReviewRequest $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('approve', $correction);

        return $this->ok(RequestResources::correction(
            $this->corrections->approve($request->user(), $correction, $request->input('note'))->load('employee'),
        ));
    }

    public function reject(ReviewRequest $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('approve', $correction);

        return $this->ok(RequestResources::correction(
            $this->corrections->reject($request->user(), $correction, (string) $request->input('note'))->load('employee'),
        ));
    }

    public function cancel(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('cancel', $correction);

        return $this->ok(RequestResources::correction($this->corrections->cancel($request->user(), $correction)->load('employee')));
    }
}
