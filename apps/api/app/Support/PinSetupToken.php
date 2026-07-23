<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The short-lived token that lets a device set its PIN.
 *
 * Held in Valkey rather than a column: it exists for ten minutes, and leaving
 * spent tokens in the database buys nothing.
 *
 * It is not what proves who is calling — the request is signed by the device
 * key for that, and `status = pending_pin` is what says a PIN is wanted at all.
 * What the token adds is a bounded window and a single use, so a PIN cannot be
 * set long after the moment that authorised it.
 *
 * Shared by enrollment and by a staff PIN reset. Two copies of this drifted
 * apart once already: a reset moved a device to `pending_pin` with no way to
 * ever issue it a token, which left the device unusable and PRD §6.7 unmet.
 */
final class PinSetupToken
{
    private const TTL_MINUTES = 10;

    public static function issue(Device $device): string
    {
        $token = Str::random(48);

        Cache::put(self::key($device->getKey()), hash('sha256', $token), now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Checks a token and spends it, but only if it was right.
     *
     * Single use means used once successfully. Deleting the stored value before
     * comparing — which pull() does — meant one wrong guess destroyed the real
     * token: the driver's next attempt with the correct one failed, and anyone
     * able to send a request could do it on purpose.
     */
    public static function consume(string $deviceId, string $token): bool
    {
        $expected = Cache::get(self::key($deviceId));

        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $token))) {
            return false;
        }

        Cache::forget(self::key($deviceId));

        return true;
    }

    public static function expiresAt(): string
    {
        return now()->addMinutes(self::TTL_MINUTES)->toIso8601String();
    }

    private static function key(string $deviceId): string
    {
        return "pin_setup:{$deviceId}";
    }
}
