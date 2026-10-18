<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecisionRequest;
use App\Models\AttendanceCorrection;
use App\Services\Admin\ApprovalQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CorrectionController extends Controller
{
    public function index(Request $request, ApprovalQueue $queue): View
    {
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'pending';

        return view('admin.attendance.corrections', [
            'corrections' => $queue->corrections($request->user(), $tab),
            'tab' => $tab,
        ]);
    }

    public function decide(DecisionRequest $request, AttendanceCorrection $correction, ApprovalQueue $queue): RedirectResponse
    {
        $queue->decideCorrection($request->user(), $correction, $request->approves(), $request->validated('note'));

        return redirect()->route('admin.attendance.corrections')
            ->with('success', $request->approves() ? 'Correction approved and attendance updated.' : 'Correction rejected.');
    }
}
