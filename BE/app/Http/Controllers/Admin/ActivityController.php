<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityIndexRequest;
use App\Services\Admin\ActivityLog;
use Illuminate\View\View;

/** Read-only: the audit trail has no create, update or delete routes. */
class ActivityController extends Controller
{
    public function __invoke(ActivityIndexRequest $request, ActivityLog $log): View
    {
        $filters = $request->filters();

        return view('admin.activity.index', [
            'activities' => $log->paginate($filters),
            'filters' => $filters,
            'options' => $log->filterOptions(),
        ]);
    }
}
