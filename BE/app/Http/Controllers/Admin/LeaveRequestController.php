<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecisionRequest;
use App\Http\Requests\Admin\LeaveIndexRequest;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Admin\LeaveActions;
use App\Services\Admin\LeaveDesk;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function index(LeaveIndexRequest $request, LeaveDesk $desk, CompanyContext $context): View
    {
        $tab = in_array($request->query('tab'), ['history', 'calendar'], true) ? $request->query('tab') : 'pending';
        $user = $request->user();
        $canReview = $user->hasAnyPermission('leave.approve', 'leave.approve_team');
        if ($tab === 'pending' && ! $canReview) {
            $tab = 'history';
        }

        $data = [
            'tab' => $tab,
            'canReview' => $canReview,
            'filters' => $request->filters(),
            'types' => LeaveType::query()->where('company_id', $context->id())->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'departments' => Department::query()->where('company_id', $context->id())->orderBy('name')->pluck('name', 'id')->all(),
        ];

        if ($tab === 'calendar') {
            $month = $request->validated('month') ?? $context->today()->format('Y-m');

            return view('admin.leave.calendar', $data + $desk->month($user, $month));
        }

        return view('admin.leave.index', $data + ['requests' => $desk->paginate($user, $tab, $request->filters())]);
    }

    /** Full page, or just the panel when the drawer asks for it (?panel=1). */
    public function show(Request $request, LeaveRequest $leaveRequest, LeaveDesk $desk): View
    {
        Gate::authorize('view', $leaveRequest);
        abort_if($leaveRequest->status === LeaveStatus::Draft, 404); // drafts are private to their author (the app)

        $data = $desk->detail($request->user(), $leaveRequest);

        return view($request->boolean('panel') ? 'admin.leave._detail' : 'admin.leave.show', $data);
    }

    public function decide(DecisionRequest $request, LeaveRequest $leaveRequest, LeaveActions $actions): RedirectResponse
    {
        $actions->decide($request->user(), $leaveRequest, $request->approves(), $request->validated('note'));

        return redirect()->route('admin.leave-requests')->with('success', $request->approves()
            ? 'Leave approved. The employee has been notified.'
            : 'Leave rejected. The employee sees your reason.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveActions $actions): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $actions->cancel($request->user(), $leaveRequest, $reason);

        return back()->with('success', 'Leave cancelled and the days returned to the balance.');
    }
}
