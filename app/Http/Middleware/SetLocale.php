<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];
    private const COOKIE    = 'locale';
    private const DEFAULT   = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Query-string ?lang=en sets the preference and persists it via cookie
        if ($request->has('lang') && in_array($request->query('lang'), self::SUPPORTED, true)) {
            $locale = $request->query('lang');
        } else {
            // 2. Cookie from a previous choice
            $locale = $request->cookie(self::COOKIE);
        }

        // 3. Fall back to default if locale is missing or unsupported
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        // Set BEFORE the request is handled so views render in the right language
        app()->setLocale($locale);

        $response = $next($request);

        // Persist the choice in a long-lived cookie
        return $response->withCookie(
            cookie()->forever(self::COOKIE, $locale, 0, '/', null, false, false, false, 'Lax')
        );
    }
}
