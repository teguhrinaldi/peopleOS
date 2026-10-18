<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PositionRequest;
use App\Models\Position;
use App\Services\Admin\OrganizationService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(Request $request, OrganizationService $organization, CompanyContext $context): View
    {
        $term = trim((string) $request->query('q'));
        $positions = Position::query()
            ->where('company_id', $context->id())
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '\\%_').'%')
                ->orWhere('code', 'like', '%'.addcslashes($term, '\\%_').'%')))
            ->when($request->query('state') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->query('state') === 'active', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.positions.index', [
            'positions' => $positions,
            'headcount' => $organization->headcountBy('position_id'),
            'q' => $term,
            'state' => $request->query('state'),
        ]);
    }

    public function store(PositionRequest $request, OrganizationService $organization): RedirectResponse
    {
        $position = $organization->savePosition(null, $request->validated(), $request->user());

        return redirect()->route('admin.positions.index')->with('success', "{$position->name} was created.");
    }

    public function update(PositionRequest $request, Position $position, OrganizationService $organization): RedirectResponse
    {
        $organization->savePosition($position, $request->validated(), $request->user());

        return redirect()->route('admin.positions.index')->with('success', "{$position->name} was updated.");
    }

    public function destroy(Request $request, Position $position, OrganizationService $organization): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('organization.manage'), 403);

        $organization->deletePosition($position, $request->user());

        return redirect()->route('admin.positions.index')->with('success', "{$position->name} was deleted.");
    }
}
