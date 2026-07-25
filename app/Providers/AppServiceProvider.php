<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters live here rather than in a dedicated provider,
     * since this is already the project's one place for cross-cutting
     * boot-time application configuration.
     */
    private function configureRateLimiting(): void
    {
        // Keyed primarily by the authenticated staff member's own ID, so
        // two different administrators behind the same IP never share a
        // bucket. The IP fallback only applies if this limiter is ever
        // evaluated without an authenticated user - it never is on the
        // route this protects today, since 'auth' always runs first.
        RateLimiter::for('staff-role-changes', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(10)->by((string) $key);
        });
    }
}
