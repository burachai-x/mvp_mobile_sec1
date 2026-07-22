<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\ApiError;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\DeviceTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * PIN setup and unlock (architecture.md §6.4).
 *
 * Six digits is 10^6, so the PIN itself is weak by construction. What makes it
 * acceptable is that it is never the only factor — every request also carries a
 * hardware-backed device signature — and that guessing is bounded by lockout
 * rather than by the keyspace.
 *
 * Verification happens here and nowhere else. If the app compared the PIN
 * locally, whoever holds a stolen phone could try all million offline.
 */
final class PinController
{
    /** Sets the first PIN, or a replacement after staff reset it. */
    public function store(Request $request, string $deviceId): JsonResponse
    {
        try {
            $data = $request->validate([
                'pin_setup_token' => ['required', 'string'],
                'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            ]);
        } catch (ValidationException $e) {
            return ApiError::make($request, 'E_VALIDATION_FAILED', 'Request payload is not valid.', 400, [
                'details' => $e->errors(),
            ]);
        }

        $device = Device::query()->whereKey($deviceId)->where('status', 'pending_pin')->first();

        if ($device === null) {
            return ApiError::deviceSignatureInvalid($request);
        }

        // pull() makes it single-use: a leaked setup token cannot be replayed to
        // overwrite the PIN later.
        $expected = Cache::pull("pin_setup:{$deviceId}");

        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $data['pin_setup_token']))) {
            return ApiError::deviceSignatureInvalid($request);
        }

        if ($this->isWeak($data['pin'])) {
            return ApiError::make(
                $request,
                'E_VALIDATION_FAILED',
                'This PIN is too easy to guess. Choose another.',
                422,
            );
        }

        DB::transaction(function () use ($device, $data, $request): void {
            $device->forceFill([
                // Argon2id, cost from config/hashing.php. Deliberately slow so a
                // leaked hash is not worth brute-forcing offline.
                'pin_hash' => Hash::make($data['pin']),
                'pin_failed_count' => 0,
                'pin_locked_until' => null,
                'status' => 'active',
            ])->save();

            AuditLog::create([
                'actor_type' => 'device',
                'actor_id' => $device->getKey(),
                'action' => 'pin.set',
                'subject_type' => 'device',
                'subject_id' => $device->getKey(),
                'ip' => $request->ip(),
            ]);
        });

        return response()->json(
            DeviceTokens::make()->issue($device, $request->ip(), $request->userAgent())
        );
    }

    /** Unlocks an already-active device. */
    public function verify(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'device_id' => ['required', 'string'],
                'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            ]);
        } catch (ValidationException $e) {
            return ApiError::make($request, 'E_VALIDATION_FAILED', 'Request payload is not valid.', 400, [
                'details' => $e->errors(),
            ]);
        }

        $device = Device::query()->whereKey($data['device_id'])->where('status', 'active')->first();

        if ($device === null) {
            return ApiError::deviceSignatureInvalid($request);
        }

        if ($device->pin_locked_until !== null && $device->pin_locked_until->isFuture()) {
            return ApiError::make($request, 'E_PIN_LOCKED', 'Too many failed attempts.', 423, [
                'retry_after' => now()->diffInSeconds($device->pin_locked_until),
            ]);
        }

        if (! Hash::check($data['pin'], (string) $device->pin_hash)) {
            return $this->recordFailure($request, $device);
        }

        $device->forceFill(['pin_failed_count' => 0, 'pin_locked_until' => null])->save();

        return response()->json(
            DeviceTokens::make()->issue($device, $request->ip(), $request->userAgent())
        );
    }

    private function recordFailure(Request $request, Device $device): JsonResponse
    {
        $failures = $device->pin_failed_count + 1;
        $maxAttempts = (int) config('security.pin.max_attempts');
        $blockAfter = (int) config('security.pin.block_after_total_failures');

        $attributes = ['pin_failed_count' => $failures];

        // Two thresholds: a short lockout absorbs fat fingers, while a sustained
        // run means someone is working through the keyspace and staff should
        // have to get involved (§6.4).
        if ($failures >= $blockAfter) {
            $attributes['status'] = 'blocked';
        } elseif ($failures % $maxAttempts === 0) {
            $attributes['pin_locked_until'] = now()->addMinutes((int) config('security.pin.lockout_minutes'));
        }

        DB::transaction(function () use ($device, $attributes, $request, $failures): void {
            $device->forceFill($attributes)->save();

            AuditLog::create([
                'actor_type' => 'device',
                'actor_id' => $device->getKey(),
                'action' => ($attributes['status'] ?? null) === 'blocked' ? 'device.blocked' : 'pin.failed',
                'subject_type' => 'device',
                'subject_id' => $device->getKey(),
                'ip' => $request->ip(),
                'meta' => ['failed_count' => $failures],
            ]);
        });

        if (($attributes['status'] ?? null) === 'blocked') {
            return ApiError::make(
                $request,
                'E_PIN_LOCKED',
                'Device is blocked. Contact staff.',
                423,
            );
        }

        if (isset($attributes['pin_locked_until'])) {
            return ApiError::make($request, 'E_PIN_LOCKED', 'Too many failed attempts.', 423, [
                'retry_after' => (int) config('security.pin.lockout_minutes') * 60,
            ]);
        }

        // Same shape as a signature failure so a wrong PIN is not distinguishable
        // from a device that cannot authenticate at all.
        return ApiError::deviceSignatureInvalid($request);
    }

    /**
     * Rejects the handful of PINs a guesser would try first. Not a substitute
     * for lockout — it just removes the cheapest wins.
     */
    private function isWeak(string $pin): bool
    {
        if (preg_match('/^(\d)\1{5}$/', $pin) === 1) {
            return true;
        }

        $ascending = '01234567890';
        $descending = '09876543210';

        if (str_contains($ascending, $pin) || str_contains($descending, $pin)) {
            return true;
        }

        return in_array($pin, [
            '123456', '654321', '111111', '000000', '121212', '112233',
            '123123', '696969', '159753', '147258', '102030', '202020',
        ], true);
    }
}
