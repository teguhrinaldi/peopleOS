<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LeavePolicyRequest;
use App\Http\Requests\Admin\LeaveTypeRequest;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveSetupService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Leave types and their policies (leave.manage). All writes go through LeaveSetupService. */
class LeaveTypeController extends Controller
{
    public function index(CompanyContext $context): View
    {
        $types = LeaveType::query()->where('company_id', $context->id())->with('policies')->orderBy('sort_order')->orderBy('name')->get();
        $usage = LeaveRequest::query()->whereIn('leave_type_id', $types->pluck('id'))
            ->groupBy('leave_type_id')->selectRaw('leave_type_id, count(*) as total, sum(status = ?) as pending', [LeaveStatus::Pending->value])
            ->get()->keyBy('leave_type_id');

        return view('admin.leave.types', compact('types', 'usage'));
    }

    public function create(): View
    {
        return view('admin.leave.type-form', ['type' => null, 'policy' => new LeavePolicy, 'inUse' => false]);
    }

    public function store(LeaveTypeRequest $request, LeaveSetupService $setup): RedirectResponse
    {
        $type = $setup->createType($request->typeData(), $request->policyData(), $request->user());

        return redirect()->route('admin.leave-types.edit', $type)->with('success', "{$type->name} was created. Add employment-type policies below if some staff get different rules.");
    }

    public function edit(LeaveType $leaveType): View
    {
        $leaveType->load('policies');

        return view('admin.leave.type-form', [
            'type' => $leaveType,
            'policy' => $leaveType->policies->firstWhere('employment_type', null) ?? new LeavePolicy,
            'inUse' => LeaveRequest::query()->where('leave_type_id', $leaveType->id)->exists(),
        ]);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType, LeaveSetupService $setup): RedirectResponse
    {
        DB::transaction(function () use ($request, $leaveType, $setup) {
            $setup->updateType($leaveType, $request->typeData(), $request->user());
            $setup->savePolicy($leaveType, ['employment_type' => null] + $request->policyData(), $request->user());
        });

        return redirect()->route('admin.leave-types.edit', $leaveType)->with('success', "{$leaveType->name} was saved. Existing balances keep their allowance; the rules apply to new requests and new leave years.");
    }

    public function destroy(Request $request, LeaveType $leaveType, LeaveSetupService $setup): RedirectResponse
    {
        $setup->deleteType($leaveType, $request->user());

        return redirect()->route('admin.leave-types')->with('success', "{$leaveType->name} was deleted.");
    }

    public function savePolicy(LeavePolicyRequest $request, LeaveType $leaveType, LeaveSetupService $setup): RedirectResponse
    {
        $setup->savePolicy($leaveType, $request->policyData(), $request->user());

        return redirect()->route('admin.leave-types.edit', $leaveType)->with('success', 'Employment-type policy saved.');
    }

    public function destroyPolicy(Request $request, LeaveType $leaveType, LeavePolicy $policy, LeaveSetupService $setup): RedirectResponse
    {
        abort_unless($policy->leave_type_id === $leaveType->id, 404);
        $setup->deletePolicy($policy, $request->user());

        return redirect()->route('admin.leave-types.edit', $leaveType)->with('success', 'Policy removed; those employees now follow the default policy.');
    }
}
