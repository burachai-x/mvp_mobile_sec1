<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Staff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Step-up authentication for personal data (architecture.md §9.2).
 *
 * Logging in is not enough to read a driver's national ID or open a scan of
 * their ID card. A second factor — a PIN the staff member keeps separately from
 * their password — has to be presented, and the elevation it grants expires.
 *
 * Having the PIN is not sufficient on its own either: the account also needs the
 * matching permission. Both are required, so a leaked PIN alone buys nothing and
 * a permission alone buys nothing.
 */
final class Elevation
{
    private const SESSION_KEY = 'pii_elevation';

    /**
     * @return array{elevation_id: string, expires_at: Carbon, scopes: list<string>}
     *
     * @throws RuntimeException on a wrong PIN, a locked account, or no permissions
     */
    public function grant(Staff $staff, string $pin, string $reason): array
    {
        if ($staff->pin_locked_until !== null && $staff->pin_locked_until->isFuture()) {
            throw new RuntimeException('PIN is temporarily locked.');
        }

        $scopes = $this->scopesFor($staff);

        if ($scopes === []) {
            // Refuse before checking the PIN: an account with no PII permissions
            // has nothing to elevate to, and letting it burn attempts would turn
            // this into a PIN oracle for accounts that cannot use it anyway.
            throw new RuntimeException('This account has no personal-data permissions.');
        }

        if ($staff->data_access_pin_hash === null || ! Hash::check($pin, $staff->data_access_pin_hash)) {
            $this->recordFailure($staff);

            throw new RuntimeException('PIN is not correct.');
        }

        $staff->forceFill(['pin_failed_count' => 0, 'pin_locked_until' => null])->save();

        $elevationId = (string) Str::uuid();
        $ttl = (int) config('security.staff_pin.elevation_ttl', 10);
        $expiresAt = now()->addMinutes($ttl);

        session([self::SESSION_KEY => [
            'id' => $elevationId,
            'staff_id' => $staff->getKey(),
            'reason' => $reason,
            'expires_at' => $expiresAt->getTimestamp(),
            'scopes' => $scopes,
        ]]);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => $staff->getKey(),
            'action' => 'staff.elevated',
            'ip' => request()->ip(),
            'meta' => ['elevation_id' => $elevationId, 'reason' => $reason, 'scopes' => $scopes],
        ]);

        return ['elevation_id' => $elevationId, 'expires_at' => $expiresAt, 'scopes' => $scopes];
    }

    /**
     * @return array{id: string, reason: string}|null
     */
    public function current(Staff $staff, string $scope): ?array
    {
        /** @var array<string, mixed>|null $elevation */
        $elevation = session(self::SESSION_KEY);

        if (! is_array($elevation)) {
            return null;
        }

        // Tie it to the staff member as well as the session: a session fixated
        // or replayed onto a different account must not carry elevation with it.
        if (($elevation['staff_id'] ?? null) !== $staff->getKey()) {
            return null;
        }

        if (($elevation['expires_at'] ?? 0) < now()->getTimestamp()) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        if (! in_array($scope, $elevation['scopes'] ?? [], true)) {
            return null;
        }

        return ['id' => (string) $elevation['id'], 'reason' => (string) $elevation['reason']];
    }

    /**
     * Records that personal data was actually reached.
     *
     * Logging the elevation alone would only show that someone typed their PIN,
     * not what they went on to look at — and PDPA asks the second question
     * (§9.3). Every read gets its own entry, carrying the elevation it happened
     * under so a burst can be traced back to one authorisation.
     */
    public function recordAccess(Staff $staff, string $action, string $subjectType, string $subjectId, array $meta = []): void
    {
        $elevation = session(self::SESSION_KEY);

        $this->flagUnusualVolume($staff, $action);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => $staff->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => request()->ip(),
            'meta' => array_merge($meta, [
                'elevation_id' => is_array($elevation) ? ($elevation['id'] ?? null) : null,
                'reason' => is_array($elevation) ? ($elevation['reason'] ?? null) : null,
            ]),
        ]);
    }

    /**
     * Bulk extraction by someone who legitimately holds the permission cannot be
     * prevented — only noticed (§9.4). Crossing the hourly ceiling is what a
     * scrape looks like, so it is written to the log where an alert can pick it
     * up rather than being silently absorbed.
     *
     * Deliberately does not block: refusing a registrar mid-shift over a busy
     * afternoon is worse than a false alarm, and the audit trail is the control
     * that matters here.
     */
    private function flagUnusualVolume(Staff $staff, string $action): void
    {
        $limits = [
            'driver.pii_viewed' => (int) config('security.staff_pin.reveal_alert_per_hour', 60),
            'document.viewed' => (int) config('security.staff_pin.download_alert_per_hour', 30),
        ];

        if (! isset($limits[$action])) {
            return;
        }

        $key = "pii-volume:{$action}:{$staff->getKey()}";

        RateLimiter::hit($key, 3600);

        if (RateLimiter::attempts($key) === $limits[$action] + 1) {
            // Once per breach, not once per request after it: an alert that
            // repeats on every call gets muted, and then nobody sees the next one.
            Log::warning('Unusual personal-data access volume', [
                'staff_id' => $staff->getKey(),
                'staff_email' => $staff->email,
                'action' => $action,
                'attempts_this_hour' => RateLimiter::attempts($key),
                'threshold' => $limits[$action],
            ]);
        }
    }

    public function revoke(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** @return list<string> */
    private function scopesFor(Staff $staff): array
    {
        return array_values(array_filter([
            $staff->can_view_pii ? 'view_pii' : null,
            $staff->can_download_documents ? 'download_documents' : null,
        ]));
    }

    private function recordFailure(Staff $staff): void
    {
        $failures = $staff->pin_failed_count + 1;
        $max = (int) config('security.staff_pin.max_attempts', 5);

        $attributes = ['pin_failed_count' => $failures];

        if ($failures % $max === 0) {
            $attributes['pin_locked_until'] = now()->addMinutes(
                (int) config('security.staff_pin.lockout_minutes', 15)
            );
        }

        $staff->forceFill($attributes)->save();

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => $staff->getKey(),
            'action' => 'staff.elevation_failed',
            'ip' => request()->ip(),
            'meta' => ['failed_count' => $failures],
        ]);
    }
}
