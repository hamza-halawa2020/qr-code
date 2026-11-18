<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Counter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to display/board routes.
 * Reads the branch's configured display_locale and sets it as the app locale,
 * overriding any customer cookie preference.
 */
class SetDisplayLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // The route parameter is either {branch} or {counter}
        $branch = $request->route('branch');
        $counter = $request->route('counter');

        if ($counter instanceof Counter) {
            $branch = $counter->branch;
        }

        if ($branch instanceof Branch) {
            $locale = $branch->display_locale;
            if (in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
