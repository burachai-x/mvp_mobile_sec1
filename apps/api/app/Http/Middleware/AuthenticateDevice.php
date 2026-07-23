<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Support\DeviceTokens;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer access token, for endpoints a device calls once it is unlocked.
 *
 * Layered on top of VerifyDeviceSignature rather than replacing it. The
 * signature proves which device is calling; this proves the driver has unlocked
 * it recently. A stolen access token on its own signs nothing, and a signature
 * on its own says nothing about whether anyone entered a PIN.
 */
final class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return ApiError::make($request, 'E_TOKEN_EXPIRED', 'Access token is required.', 401);
        }

        try {
            $claims = DeviceTokens::make()->readAccessToken($token);
        } catch (RuntimeException) {
            // Expired and malformed answer alike: the app's move is the same
            // either way, which is to refresh and retry.
            return ApiError::make($request, 'E_TOKEN_EXPIRED', 'Access token is not usable.', 401);
        }

        /** @var Device|null $signed */
        $signed = $request->attributes->get('device');

        // The token has to belong to the device that signed the request.
        // Without this, a token lifted from one phone could be spent from
        // another that happens to be enrolled.
        if ($signed === null || $signed->getKey() !== $claims['device_id']) {
            return ApiError::deviceSignatureInvalid($request);
        }

        if ($signed->status !== 'active') {
            return ApiError::make($request, 'E_DEVICE_REVOKED', 'Device is no longer active.', 401);
        }

        // A token stays cryptographically valid until it expires, so revoking a
        // session has to be checked here or staff revoking a device would not
        // take effect until the token ran out on its own.
        $live = DeviceSession::query()
            ->where('access_jti', $claims['jti'])
            ->whereNull('revoked_at')
            ->exists();

        if (! $live) {
            return ApiError::make($request, 'E_TOKEN_EXPIRED', 'Session has been revoked.', 401);
        }

        return $next($request);
    }
}
