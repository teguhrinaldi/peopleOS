<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AttendanceCaptureRequest;
use App\Http\Requests\Api\ReviewRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\AccessScope;
use App\Services\Attendance\AttendanceResult;
use App\Services\Attendance\AttendanceReviewService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\TodayService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends ApiController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly TodayService $today,
        private readonly AccessScope $scope,
        private readonly AttendanceReviewService $reviews,
    ) {}

    public function today(Request $request): JsonResponse
    {
        return $this->ok($this->today->snapshot($this->employee($request)));
    }

    public function checkIn(AttendanceCaptureRequest $request): JsonResponse
    {
        return $this->respond($this->attendance->checkIn($this->employee($request), $request->toCapture(), $request->file('selfie')));
    }

    public function checkOut(AttendanceCaptureRequest $request): JsonResponse
    {
        return $this->respond($this->attendance->checkOut($this->employee($request), $request->toCapture(), $request->file('selfie')));
    }

    /**
     * History. scope=mine (default) | team | all. `employee_id` narrows a team/all query and is always
     * intersected with what the caller is allowed to see, so a guessed id can only ever return 404/empty.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,team,all'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $scope = $request->query('scope', 'mine');

        $query = Attendance::query()->with(['schedule', 'events']);

        if ($scope === 'mine') {
            $query->where('employee_id', $this->employee($request)->id);
        } elseif ($scope === 'team') {
            if (! $user->hasPermission('attendance.view_team')) {
                throw ApiException::make('FORBIDDEN', 'You do not have permission to view team attendance.', 403);
            }
            $query->whereIn('employee_id', Employee::query()->directReportsOf($this->employee($request)->id)->select('employees.id'));
        } else {
            if (! $user->hasPermission('attendance.view')) {
                throw ApiException::make('FORBIDDEN', 'You do not have permission to view all attendance.', 403);
            }
            $query->whereIn('employee_id', $this->scope->employees($user, 'attendance.view', null, false)->select('employees.id'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->query('employee_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->query('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }

        $page = $query->orderByDesc('work_date')->orderByDesc('id')->paginate($this->perPage($request, 31));

        return $this->paginated($page, fn (Attendance $a) => (new AttendanceResource($a))->resolve());
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return $this->ok((new AttendanceResource($attendance->load(['schedule', 'events'])))->resolve());
    }

    /** HR decision on attendance flagged by the "review" geofence mode. */
    public function approve(ReviewRequest $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('review', $attendance);
        $reviewed = $this->reviews->approve($request->user(), $attendance, $request->input('note'));

        return $this->ok((new AttendanceResource($reviewed->load(['schedule', 'events'])))->resolve());
    }

    public function reject(ReviewRequest $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('review', $attendance);
        $reviewed = $this->reviews->reject($request->user(), $attendance, (string) $request->input('note'));

        return $this->ok((new AttendanceResource($reviewed->load(['schedule', 'events'])))->resolve());
    }

    /** Private evidence. Streamed only after the policy passes; the storage path is never exposed. */
    public function selfie(Request $request, AttendanceEvent $event): StreamedResponse
    {
        $this->authorize('viewSelfie', $event->attendance);

        $disk = Storage::disk(config('peopleos.attendance.selfie_disk'));
        if (! $event->selfie_path || ! $disk->exists($event->selfie_path)) {
            throw ApiException::make('NOT_FOUND', 'No selfie was recorded for this event.', 404);
        }

        return $disk->response($event->selfie_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function respond(AttendanceResult $result): JsonResponse
    {
        $attendance = $result->attendance->load(['schedule', 'events']);

        return $this->ok([
            'attendance' => (new AttendanceResource($attendance))->resolve(),
            'event' => [
                'id' => $result->event->id,
                'type' => $result->event->type->value,
                'occurred_at' => Fmt::iso($result->event->occurred_at),
                'distance_meters' => $result->event->distance_meters,
                'inside_geofence' => $result->event->inside_geofence,
            ],
            'replayed' => $result->replayed,
        ], $result->replayed ? 200 : 201);
    }
}
