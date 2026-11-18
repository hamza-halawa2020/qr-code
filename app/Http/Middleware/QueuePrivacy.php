<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class QueuePrivacy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->is('t/*', 'display/*', 'admin*', 'branches/*', 'board/*', 'appointments/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
