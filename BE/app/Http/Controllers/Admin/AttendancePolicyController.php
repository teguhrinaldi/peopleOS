<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendancePolicyRequest;
use App\Models\AttendancePolicy;
use App\Models\WorkLocation;
use App\Services\Admin\LocationService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendancePolicyController extends Controller
{
    public function index(CompanyContext $context): View
    {
        return view('admin.locations.policies', [
            'policies' => AttendancePolicy::query()->where('company_id', $context->id())
                ->orderByDesc('is_default')->orderBy('name')->get(),
            'locationCounts' => WorkLocation::query()->where('company_id', $context->id())->whereNotNull('attendance_policy_id')
                ->groupBy('attendance_policy_id')->selectRaw('attendance_policy_id, count(*) as n')->pluck('n', 'attendance_policy_id')->all(),
        ]);
    }

    public function store(AttendancePolicyRequest $request, LocationService $locations): RedirectResponse
    {
        $policy = $locations->savePolicy(null, $request->validated(), $request->user());

        return redirect()->route('admin.work-locations.policies')->with('success', "{$policy->name} was created.");
    }

    public function update(AttendancePolicyRequest $request, AttendancePolicy $policy, LocationService $locations): RedirectResponse
    {
        $locations->savePolicy($policy, $request->validated(), $request->user());

        return redirect()->route('admin.work-locations.policies')->with('success', "{$policy->name} was updated. Attendance already recorded keeps the old rules.");
    }

    public function destroy(Request $request, AttendancePolicy $policy, LocationService $locations): RedirectResponse
    {
        $locations->deletePolicy($policy, $request->user());

        return redirect()->route('admin.work-locations.policies')->with('success', "{$policy->name} was deleted.");
    }
}
