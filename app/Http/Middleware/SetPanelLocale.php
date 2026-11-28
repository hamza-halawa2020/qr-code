<?php

namespace App\Http\Middleware;

use App\Models\Branch;
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
            $branchId = $request->query('branch_id')
                ?? $request->session()->get('staff.branch_id')
                ?? $user->branch_id;
            $branch = $branchId ? Branch::where('is_active', true)->find($branchId) : null;
            $locale = $branch?->panel_locale ?? 'ar';

            if (in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
