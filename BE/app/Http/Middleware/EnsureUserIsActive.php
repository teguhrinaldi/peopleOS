<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Stops disabled accounts and terminated employees even when they still hold a valid token. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();
            throw ApiException::make('ACCOUNT_DISABLED', 'This account has been disabled.', 403);
        }

        return $next($request);
    }
}
