<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        return view('admin.dashboard', ['d' => $dashboard->forUser($request->user())]);
    }

    /** The attendance trend chart alone (range switch and retry on the dashboard), scoped to the viewer like the page. */
    public function trend(Request $request, DashboardService $dashboard): View
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'in:'.implode(',', DashboardService::TREND_RANGES)]]);
        $days = (int) ($validated['days'] ?? DashboardService::TREND_DAYS);
        $trend = $dashboard->trend($request->user(), $days);

        return view('admin.partials.attendance-trend', ['days' => $trend['days'], 'meta' => $trend['meta']]);
    }
}
