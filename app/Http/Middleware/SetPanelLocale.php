<?php

namespace App\Http\Middleware;

use App\Services\BranchAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to the Filament admin panel.
 * Reads the authenticated user's branch panel_locale and applies it.
 */
class SetPanelLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user) {
            $branches = app(BranchAccessService::class)->branches($user);
            // Use the first accessible branch's panel locale
            $locale = $branches->first()?->panel_locale ?? 'ar';

            if (in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
