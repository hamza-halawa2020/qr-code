<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];
    private const COOKIE    = 'locale';
    private const DEFAULT   = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Query-string ?lang=en sets the preference
        if ($request->has('lang') && in_array($request->query('lang'), self::SUPPORTED, true)) {
            $locale = $request->query('lang');
            // Queue the cookie so it persists across redirects and Livewire responses
            Cookie::queue(Cookie::forever(self::COOKIE, $locale, '/', null, false, false, false, 'Lax'));
        } else {
            // 2. Cookie from a previous choice
            $locale = $request->cookie(self::COOKIE);
        }

        // 3. Fall back to default if locale is missing or unsupported
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
