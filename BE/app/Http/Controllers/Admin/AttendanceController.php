<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceBoardRequest;
use App\Http\Requests\Admin\DecisionRequest;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\WorkLocation;
use App\Services\ActivityLogger;
use App\Services\Admin\ApprovalQueue;
use App\Services\Admin\AttendanceBoard;
use App\Services\Admin\AttendanceRecordView;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index(AttendanceBoardRequest $request, AttendanceBoard $board, CompanyContext $context): View
    {
        $filters = $request->filters();
        $user = $request->user();

        return view('admin.attendance.index', [
            'records' => $board->paginate($user, $filters),
            'summary' => $board->summary($user, $filters),
            'missing' => $board->notCheckedIn($user, $filters),
            'window' => $board->window($filters),
            'filters' => $filters,
            'departments' => Department::query()->where('company_id', $context->id())->orderBy('name')->pluck('name', 'id')->all(),
            'locations' => WorkLocation::query()->where('company_id', $context->id())->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => AttendanceBoard::statusOptions(),
        ]);
    }

    public function export(AttendanceBoardRequest $request, AttendanceBoard $board, ActivityLogger $activity): StreamedResponse
    {
        $filters = $request->filters();
        $user = $request->user();
        $window = $board->window($filters);
        $activity->log('attendance.exported', 'attendance', null, array_filter($filters + $window), actor: $user);

        $name = 'attendance-'.($window['from'] ?? 'all').($window['to'] && $window['to'] !== $window['from'] ? '_'.$window['to'] : '').'.csv';

        return response()->streamDownload(function () use ($board, $user, $filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // lets spreadsheet apps detect UTF-8
            fputcsv($out, AttendanceBoard::exportHeader());
            foreach ($board->exportRows($user, $filters) as $row) {
                fputcsv($out, array_map(self::csvSafe(...), $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function show(Request $request, Attendance $attendance, AttendanceRecordView $view): View
    {
        Gate::authorize('view', $attendance);

        return view('admin.attendance.show', $view->build($request->user(), $attendance));
    }

    public function review(DecisionRequest $request, Attendance $attendance, ApprovalQueue $queue): RedirectResponse
    {
        $result = $queue->review($request->user(), $attendance, $request->approves(), $request->validated('note'));

        return redirect()->route('admin.attendance.show', $result)
            ->with('success', $request->approves() ? 'Attendance approved.' : 'Attendance rejected; the employee will see your note.');
    }

    /** Selfies live on a private disk and are streamed only after the record policy allows it. */
    public function selfie(AttendanceEvent $event): StreamedResponse
    {
        Gate::authorize('viewSelfie', $event->attendance);

        $disk = Storage::disk(config('peopleos.attendance.selfie_disk'));
        abort_unless($event->selfie_path && $disk->exists($event->selfie_path), 404);

        return $disk->response($event->selfie_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** Stop spreadsheet formula injection from names or notes that start with = + - @. */
    private static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
