<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['queue-booking', 'appointment-booking'] as $purpose) {
            RateLimiter::for($purpose, fn (Request $request) => [
                Limit::perMinute(10)->by($purpose.'-session-'.hash('sha256', $request->session()->getId())),
                Limit::perMinute(300)->by($purpose.'-ip-'.$request->ip()),
            ]);
        }
        RateLimiter::for('queue-state', fn (Request $request) => [
            Limit::perMinute(120)->by('queue-token-'.hash('sha256', (string) $request->route('secret'))),
            Limit::perMinute(2400)->by('queue-state-ip-'.$request->ip()),
        ]);
    }
}
