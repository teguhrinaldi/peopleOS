<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecisionRequest;
use App\Models\OvertimeRequest;
use App\Services\Admin\ApprovalQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OvertimeController extends Controller
{
    public function index(Request $request, ApprovalQueue $queue): View
    {
        $tab = $request->query('tab') === 'history' ? 'history' : 'pending';
        $requests = $queue->overtime($request->user(), $tab);

        return view('admin.overtime.index', [
            'requests' => $requests,
            'tab' => $tab,
            'decidable' => $tab === 'pending' ? $queue->overtimeDecidable($request->user(), $requests->getCollection()->pluck('employee_id')->unique()->values()->all()) : [],
        ]);
    }

    public function decide(DecisionRequest $request, OvertimeRequest $overtimeRequest, ApprovalQueue $queue): RedirectResponse
    {
        $minutes = $request->validated('approved_minutes');
        $queue->decideOvertime($request->user(), $overtimeRequest, $request->approves(), $minutes !== null ? (int) $minutes : null, $request->validated('note'));

        return redirect()->route('admin.overtime')->with('success', $request->approves() ? 'Overtime approved.' : 'Overtime rejected.');
    }
}
