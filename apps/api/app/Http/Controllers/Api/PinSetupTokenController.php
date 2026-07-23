<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\ApiError;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\PinSetupToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a fresh PIN setup token to a device waiting for one.
 *
 * Exists because a staff PIN reset had no way to finish. It moved the device to
 * `pending_pin` and cleared the hash, but the only place a setup token was ever
 * created was enrollment — so the driver could not set a new PIN, could not
 * unlock, and the device had to be deleted and enrolled again. PRD §6.7 says
 * staff can reset a forgotten PIN; without this it could not.
 *
 * What authorises the call is the device signature: the key lives in the TEE
 * and a reset does not touch it, so the same phone that was enrolled is the
 * only one that can ask. `pending_pin` is what says a PIN is wanted at all — an
 * active device gets nothing here, so this cannot be used to talk a working
 * device into accepting a new PIN.
 */
final class PinSetupTokenController
{
    public function __invoke(Request $request, string $deviceId): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        // The middleware already matched the signature to this device; this
        // guards against the route and the body disagreeing.
        if ($device->getKey() !== $deviceId) {
            return ApiError::deviceSignatureInvalid($request);
        }

        if ($device->status !== 'pending_pin') {
            return ApiError::make(
                $request,
                'E_PIN_ALREADY_SET',
                'This device is not waiting for a PIN.',
                409,
            );
        }

        AuditLog::create([
            'actor_type' => 'device',
            'actor_id' => $device->getKey(),
            'action' => 'pin.setup_token_issued',
            'subject_type' => 'device',
            'subject_id' => $device->getKey(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'pin_setup_token' => PinSetupToken::issue($device),
            'expires_at' => PinSetupToken::expiresAt(),
        ]);
    }
}
