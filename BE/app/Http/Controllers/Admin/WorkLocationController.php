<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WorkLocationRequest;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\WorkLocation;
use App\Services\Admin\LocationService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkLocationController extends Controller
{
    public function index(Request $request, LocationService $locations, CompanyContext $context): View
    {
        $term = trim((string) $request->query('q'));

        return view('admin.locations.index', [
            'locations' => WorkLocation::query()->where('company_id', $context->id())
                ->with(['branch:id,name', 'attendancePolicy:id,name,geofence_mode,require_selfie'])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '\\%_').'%')->orWhere('code', 'like', '%'.addcslashes($term, '\\%_').'%')))
                ->orderByDesc('is_active')->orderBy('name')->paginate(25)->withQueryString(),
            'usage' => $locations->usage(),
            'defaultPolicy' => AttendancePolicy::query()->where('company_id', $context->id())->where('is_default', true)->value('name'),
            'q' => $term,
        ]);
    }

    public function create(CompanyContext $context): View
    {
        return view('admin.locations.form', ['location' => null] + $this->options($context));
    }

    public function store(WorkLocationRequest $request, LocationService $locations): RedirectResponse
    {
        $location = $locations->saveLocation(null, $request->validated(), $request->user());

        return redirect()->route('admin.work-locations')->with('success', "{$location->name} was created.");
    }

    public function edit(WorkLocation $location, CompanyContext $context): View
    {
        return view('admin.locations.form', ['location' => $location] + $this->options($context));
    }

    public function update(WorkLocationRequest $request, WorkLocation $location, LocationService $locations): RedirectResponse
    {
        $locations->saveLocation($location, $request->validated(), $request->user());

        return redirect()->route('admin.work-locations')->with('success', "{$location->name} was updated. Attendance already recorded keeps the old geofence.");
    }

    public function destroy(Request $request, WorkLocation $location, LocationService $locations): RedirectResponse
    {
        $locations->deleteLocation($location, $request->user());

        return redirect()->route('admin.work-locations')->with('success', "{$location->name} was deleted.");
    }

    /** @return array<string, array<int, string>> */
    private function options(CompanyContext $context): array
    {
        return [
            'branches' => Branch::query()->where('company_id', $context->id())->orderBy('name')->pluck('name', 'id')->all(),
            'policies' => AttendancePolicy::query()->where('company_id', $context->id())->orderByDesc('is_default')->orderBy('name')->get()
                ->mapWithKeys(fn ($p) => [$p->id => $p->name.($p->is_default ? ' (default)' : '')])->all(),
        ];
    }
}
