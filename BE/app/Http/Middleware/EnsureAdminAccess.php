<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web admin gate: a live session is only valid while the account is active and still holds `admin.access`.
 * Disabled accounts are signed out; accounts that lost the permission get a 403.
 */
class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'This account has been disabled.');
        }

        abort_unless($user && $user->hasPermission('admin.access'), 403);

        return $next($request);
    }
}
