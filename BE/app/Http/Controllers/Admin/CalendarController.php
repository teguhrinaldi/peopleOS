<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalendarAssignmentRequest;
use App\Http\Requests\Admin\CalendarRequest;
use App\Models\Employee;
use App\Models\WorkingCalendar;
use App\Models\WorkingCalendarAssignment;
use App\Services\Admin\CalendarService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(CompanyContext $context): View
    {
        $today = $context->today()->toDateString();
        $calendars = WorkingCalendar::query()->where('company_id', $context->id())->with('days')->orderByDesc('is_default')->orderBy('name')->get();

        return view('admin.shifts.calendars', [
            'calendars' => $calendars,
            'assignments' => WorkingCalendarAssignment::query()
                ->whereIn('working_calendar_id', $calendars->pluck('id'))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
                ->with(['employee:id,full_name,employee_number,photo_path', 'calendar'])
                ->orderBy('effective_from')->limit(200)->get(),
            'employees' => Employee::query()->where('company_id', $context->id())->where('status', '!=', EmployeeStatus::Terminated->value)
                ->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} ({$e->employee_number})"])->all(),
            'today' => $today,
        ]);
    }

    public function store(CalendarRequest $request, CalendarService $calendars): RedirectResponse
    {
        $calendar = $calendars->save(null, $request->validated(), $request->user());

        return redirect()->route('admin.shifts.calendars')->with('success', "{$calendar->name} was created.");
    }

    public function update(CalendarRequest $request, WorkingCalendar $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->save($calendar, $request->validated(), $request->user());

        return redirect()->route('admin.shifts.calendars')->with('success', "{$calendar->name} was updated. Days already recorded keep their schedule.");
    }

    public function destroy(Request $request, WorkingCalendar $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->delete($calendar, $request->user());

        return redirect()->route('admin.shifts.calendars')->with('success', "{$calendar->name} was deleted.");
    }

    public function assign(CalendarAssignmentRequest $request, CalendarService $calendars): RedirectResponse
    {
        $calendars->assign($request->validated(), $request->user());

        return redirect()->route('admin.shifts.calendars')->with('success', 'Calendar assignment saved.');
    }

    public function unassign(Request $request, WorkingCalendarAssignment $calendarAssignment, CalendarService $calendars): RedirectResponse
    {
        $result = $calendars->endAssignment($calendarAssignment, $request->user());

        return redirect()->route('admin.shifts.calendars')->with('success', $result === 'deleted' ? 'The upcoming assignment was removed.' : 'The assignment now ends today.');
    }
}
