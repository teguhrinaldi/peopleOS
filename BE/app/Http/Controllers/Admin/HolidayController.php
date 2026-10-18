<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HolidayRequest;
use App\Models\Holiday;
use App\Services\Admin\HolidayService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(Request $request, CompanyContext $context, HolidayService $service): View
    {
        $current = (int) $context->today()->format('Y');
        $year = (int) $request->query('year', (string) $current);
        $year = $year >= 2000 && $year <= 2100 ? $year : $current;

        $years = Holiday::query()->where('company_id', $context->id())->selectRaw('distinct year(date) as y')->pluck('y')
            ->map(fn ($y) => (int) $y)->push($current, $current + 1, $year)->unique()->sort()->values();

        $holidays = Holiday::query()->where('company_id', $context->id())->whereYear('date', $year)->orderBy('date')->get();
        $frozen = $service->frozenIds($holidays->pluck('id')->all());

        return view('admin.shifts.holidays', compact('holidays', 'year', 'years', 'frozen'));
    }

    public function store(HolidayRequest $request, HolidayService $holidays): RedirectResponse
    {
        $holiday = $holidays->save(null, $request->validated(), $request->user());

        return redirect()->route('admin.shifts.holidays', ['year' => $holiday->date->format('Y')])->with('success', "{$holiday->name} was added.");
    }

    public function update(HolidayRequest $request, Holiday $holiday, HolidayService $holidays): RedirectResponse
    {
        $holidays->save($holiday, $request->validated(), $request->user());

        return redirect()->route('admin.shifts.holidays', ['year' => $holiday->date->format('Y')])->with('success', "{$holiday->name} was updated.");
    }

    public function destroy(Request $request, Holiday $holiday, HolidayService $holidays): RedirectResponse
    {
        $holidays->delete($holiday, $request->user());

        return redirect()->route('admin.shifts.holidays', ['year' => $holiday->date->format('Y')])->with('success', "{$holiday->name} was removed.");
    }
}
