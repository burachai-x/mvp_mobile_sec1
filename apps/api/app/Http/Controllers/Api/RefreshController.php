<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\ApiError;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\DeviceTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Exchanges a refresh token for a new pair (docs/api/openapi.yaml `/auth/refresh`).
 *
 * This is what lets the app unlock without the driver typing a PIN — biometric
 * unlock releases the stored refresh token and spends it here. The server stays
 * the authority either way: a fingerprint decides whether the app may reach the
 * token, never whether the session is valid.
 */
final class RefreshController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                // Carried explicitly so the signature middleware can find the
                // device before anything touches the token, exactly as
                // /auth/pin/verify does.
                'device_id' => ['required', 'string'],
                'refresh_token' => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            return ApiError::make($request, 'E_VALIDATION_FAILED', 'Request payload is not valid.', 400, [
                'details' => $e->errors(),
            ]);
        }

        /** @var Device $device */
        $device = $request->attributes->get('device');

        if ($device->status !== 'active') {
            return ApiError::deviceSignatureInvalid($request);
        }

        try {
            $tokens = DeviceTokens::make()->rotate(
                $data['refresh_token'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            // Reuse of a spent token means it leaked; DeviceTokens has already
            // killed every session for the device. Recorded here because that is
            // an event staff need to see, not a routine 401.
            AuditLog::create([
                'actor_type' => 'device',
                'actor_id' => $device->getKey(),
                'action' => 'device.refresh_rejected',
                'subject_type' => 'device',
                'subject_id' => $device->getKey(),
                'ip' => $request->ip(),
                'meta' => ['reason' => $e->getMessage()],
            ]);

            return ApiError::make($request, 'E_TOKEN_EXPIRED', 'Refresh token is not usable.', 401);
        }

        return response()->json($tokens);
    }
}
