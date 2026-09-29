<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPermissionGates();
        $this->registerRateLimiters();
    }

    /**
     * Every permission in App\Support\Authorization\Permissions becomes a Gate ability that
     * checks the authenticated user's role within the *current organization* (never
     * globally) — see User::hasPermission().
     */
    private function registerPermissionGates(): void
    {
        foreach (Permissions::ALL as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }

    private function registerRateLimiters(): void
    {
        // Brute-force protection on the login endpoint: keyed by IP + attempted email so one
        // abusive IP can't lock out every account, and one targeted account can't be
        // hammered from many IPs without also being throttled per-IP.
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        // Reusable limiter for future public, unauthenticated endpoints (signed proposal /
        // change-order links, OTP requests) — not yet attached to any route since those
        // endpoints don't exist in Sprint 1, but named here so later agents just add
        // ->middleware('throttle:public-links') instead of re-deriving limits.
        RateLimiter::for('public-links', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Platform Readiness Review finding #04 (password reset). Same per-IP+email keying
        // reasoning as 'auth-login' — a public, unauthenticated endpoint that triggers an email
        // send needs its own brute-force/spam ceiling.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        // Platform Readiness Review finding #01 (self-serve signup). Per-IP only (not
        // per-email like the two limiters above) — unlike login/password-reset, an email here
        // usually belongs to no existing account yet, so keying by email alone wouldn't stop an
        // attacker cycling through many addresses from one IP to spin up spam organizations.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
