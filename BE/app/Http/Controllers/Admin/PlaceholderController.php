<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminNavigation;
use Illuminate\View\View;

/**
 * One page for every module that is not built yet. To build a module, register a real route
 * with the same name (see routes/admin.php) and it replaces the placeholder.
 */
class PlaceholderController extends Controller
{
    public function __invoke(string $page): View
    {
        $item = AdminNavigation::find($page) ?? abort(404);

        return view('admin.placeholder', [
            'title' => $item['label'],
            'group' => $item['group'] ?? null,
            'description' => $item['placeholder'] ?? '',
            'icon' => $item['icon'],
        ]);
    }
}
