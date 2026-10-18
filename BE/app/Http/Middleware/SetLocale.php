<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the UI language for this request: the signed-in user's saved preference, then an explicit
 * `X-Locale` / `Accept-Language` header (mobile app before login), then the company default, then English.
 * Only ever changes translated text and formatting; stored data is never touched.
 */
class SetLocale
{
    public function __construct(private readonly Locales $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->locales->resolve($request));

        return $next($request);
    }
}
