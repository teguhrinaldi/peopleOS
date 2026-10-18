<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Admin\OrganizationService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request, OrganizationService $organization, CompanyContext $context): View
    {
        $term = trim((string) $request->query('q'));
        $departments = Department::query()
            ->where('company_id', $context->id())
            ->with(['branch:id,name', 'parent:id,name'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '\\%_').'%')
                ->orWhere('code', 'like', '%'.addcslashes($term, '\\%_').'%')))
            ->when($request->query('state') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->query('state') === 'active', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.departments.index', [
            'departments' => $departments,
            'headcount' => $organization->headcountBy('department_id'),
            'branches' => Branch::query()->where('company_id', $context->id())->orderBy('name')->pluck('name', 'id')->all(),
            'parents' => Department::query()->where('company_id', $context->id())->orderBy('name')->get(['id', 'name', 'code']),
            'q' => $term,
            'state' => $request->query('state'),
        ]);
    }

    public function store(DepartmentRequest $request, OrganizationService $organization): RedirectResponse
    {
        $department = $organization->saveDepartment(null, $request->validated(), $request->user());

        return redirect()->route('admin.departments.index')->with('success', "{$department->name} was created.");
    }

    public function update(DepartmentRequest $request, Department $department, OrganizationService $organization): RedirectResponse
    {
        $organization->saveDepartment($department, $request->validated(), $request->user());

        return redirect()->route('admin.departments.index')->with('success', "{$department->name} was updated.");
    }

    public function destroy(Request $request, Department $department, OrganizationService $organization): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('organization.manage'), 403);

        $organization->deleteDepartment($department, $request->user());

        return redirect()->route('admin.departments.index')->with('success', "{$department->name} was deleted.");
    }
}
