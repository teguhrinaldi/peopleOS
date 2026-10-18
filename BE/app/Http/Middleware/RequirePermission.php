<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard for the web admin: `permission:employee.view,employee.view_team` = any of the listed. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_unless($user && $user->hasAnyPermission(...$permissions), 403);

        return $next($request);
    }
}
