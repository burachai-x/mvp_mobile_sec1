<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Support\ApiError;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the driver API (docs/api/openapi.yaml `x-rate-limit`).
 *
 * Bare `throttle:10,60` is not enough here. Laravel keys those by
 * `domain|ip` only — the route is not part of the key — so every endpoint under
 * the same host shares one counter, and whichever route is hit first decides the
 * decay window for all of them. Enrollments would eat the PIN endpoint's budget.
 *
 * Named limiters get their own key prefix, and the `by()` value below scopes
 * each one to the thing that should actually be limited.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Per IP: enrollment happens before a device exists, so there is nothing
        // else to key on. Requires TrustProxies to be working, otherwise every
        // driver shares the proxy's address (bootstrap/app.php).
        RateLimiter::for('enroll', fn (Request $request) => Limit::perHour(
            (int) config('security.rate_limits.enroll_per_hour', 10)
        )->by($request->ip())->response($this->tooMany(...)));

        // Per device: one driver hammering PIN setup must not lock out everyone
        // else behind the same NAT, which is common on mobile networks.
        RateLimiter::for('pin-setup', fn (Request $request) => Limit::perHour(
            (int) config('security.rate_limits.pin_setup_per_hour', 5)
        )->by((string) ($request->route('deviceId') ?? $request->ip()))->response($this->tooMany(...)));

        // Also per device. This is a second line behind the PIN lockout in
        // PinController, which is the control that actually bounds guessing —
        // this one just stops a flood from reaching it.
        RateLimiter::for('pin-verify', fn (Request $request) => Limit::perMinute(
            (int) config('security.rate_limits.pin_verify_per_minute', 10)
        )->by((string) ($request->input('device_id') ?? $request->ip()))->response($this->tooMany(...)));

        // Biometric unlock spends a refresh token, so a driver who opens the app
        // repeatedly through the day comes through here rather than pin-verify.
        // Bounded per hour: a device needing more than this is either broken or
        // replaying, and both are worth stopping.
        // Once per launch is the intent; this leaves room for a driver who opens
        // the app repeatedly without letting a loop flood the table.
        RateLimiter::for('integrity', fn (Request $request) => Limit::perHour(
            (int) config('security.rate_limits.integrity_per_hour', 60)
        )->by((string) ($request->bearerToken() ?? $request->ip()))->response($this->tooMany(...)));

        RateLimiter::for('token-refresh', fn (Request $request) => Limit::perHour(
            (int) config('security.rate_limits.token_refresh_per_hour', 60)
        )->by((string) ($request->input('device_id') ?? $request->ip()))->response($this->tooMany(...)));
    }

    /**
     * Laravel's default 429 body is `{"message":"Too Many Attempts."}`, which
     * does not match the error envelope in §7. The app is required to branch on
     * `code` (CLAUDE.md §2), so without this it cannot tell a rate limit from
     * any other failure.
     */
    private function tooMany(Request $request, array $headers = []): mixed
    {
        return ApiError::rateLimited($request, (int) ($headers['Retry-After'] ?? 60))
            ->withHeaders($headers);
    }
}
