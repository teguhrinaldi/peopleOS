<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Http\Requests\Admin\CompanyRequest;
use App\Models\Branch;
use App\Services\Admin\OrganizationService;
use App\Support\CompanyContext;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function show(OrganizationService $organization, CompanyContext $context): View
    {
        return view('admin.organization.show', [
            'company' => $context->company(),
            'overview' => $organization->overview(),
            'branches' => Branch::query()->where('company_id', $context->id())->orderBy('name')->get(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'headcount' => $organization->headcountBy('branch_id'),
        ]);
    }

    public function updateCompany(CompanyRequest $request, OrganizationService $organization): RedirectResponse
    {
        $organization->updateCompany($request->validated(), $request->user());

        return redirect()->route('admin.organization.show')->with('success', 'Company profile saved.');
    }

    public function storeBranch(BranchRequest $request, OrganizationService $organization): RedirectResponse
    {
        $branch = $organization->saveBranch(null, $request->validated(), $request->user());

        return redirect(route('admin.organization.show').'#branches')->with('success', "{$branch->name} was created.");
    }

    public function updateBranch(BranchRequest $request, Branch $branch, OrganizationService $organization): RedirectResponse
    {
        $organization->saveBranch($branch, $request->validated(), $request->user());

        return redirect(route('admin.organization.show').'#branches')->with('success', "{$branch->name} was updated.");
    }

    public function destroyBranch(Request $request, Branch $branch, OrganizationService $organization): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('organization.manage'), 403);

        $organization->deleteBranch($branch, $request->user());

        return redirect(route('admin.organization.show').'#branches')->with('success', "{$branch->name} was deleted.");
    }
}
