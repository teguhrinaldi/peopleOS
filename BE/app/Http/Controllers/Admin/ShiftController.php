<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShiftRequest;
use App\Models\Shift;
use App\Services\Admin\ShiftSetupService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function index(ShiftSetupService $shifts, CompanyContext $context): View
    {
        return view('admin.shifts.index', [
            'shifts' => Shift::query()->where('company_id', $context->id())->with('breaks')->orderByDesc('is_active')->orderBy('start_time')->get(),
            'usage' => $shifts->usage(),
        ]);
    }

    public function create(): View
    {
        return view('admin.shifts.form', ['shift' => null]);
    }

    public function store(ShiftRequest $request, ShiftSetupService $shifts): RedirectResponse
    {
        $shift = $shifts->save(null, $request->shiftData(), $request->user());

        return redirect()->route('admin.shifts')->with('success', "{$shift->name} was created.");
    }

    public function edit(Shift $shift): View
    {
        return view('admin.shifts.form', ['shift' => $shift->load('breaks')]);
    }

    public function update(ShiftRequest $request, Shift $shift, ShiftSetupService $shifts): RedirectResponse
    {
        $shifts->save($shift, $request->shiftData(), $request->user());

        return redirect()->route('admin.shifts')->with('success', "{$shift->name} was updated. Schedules already recorded keep their old times.");
    }

    public function destroy(Request $request, Shift $shift, ShiftSetupService $shifts): RedirectResponse
    {
        $shifts->delete($shift, $request->user());

        return redirect()->route('admin.shifts')->with('success', "{$shift->name} was deleted.");
    }
}
