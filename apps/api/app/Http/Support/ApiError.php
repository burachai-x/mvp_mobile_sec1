<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single source of the error envelope defined in docs/api/openapi.yaml §7.
 *
 * `message` is English and written for developers. The app must branch on
 * `code` and render its own Thai text — anything else would put untranslated
 * strings in front of drivers (CLAUDE.md §2).
 */
final class ApiError
{
    public static function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $extra = [],
    ): JsonResponse {
        return response()->json([
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
                // Lets support trace a driver's complaint back to a log line
                // without asking them for anything sensitive.
                'request_id' => $request->header('X-Request-Id') ?? $request->getRequestUri(),
            ], $extra),
        ], $status);
    }

    public static function appSignatureInvalid(Request $request): JsonResponse
    {
        return self::make($request, 'E_APP_SIGNATURE_INVALID', 'App signature is not recognised.', 403);
    }

    public static function deviceSignatureInvalid(Request $request): JsonResponse
    {
        // Deliberately one message for every signature failure — a bad signature,
        // a replayed nonce and a skewed clock must be indistinguishable, or the
        // response becomes an oracle for tuning an attack.
        return self::make($request, 'E_DEVICE_SIGNATURE_INVALID', 'Request signature is not valid.', 401);
    }

    public static function updateRequired(Request $request, string $minVersion): JsonResponse
    {
        return self::make($request, 'E_APP_UPDATE_REQUIRED', 'A newer app version is required.', 426, [
            'min_supported_version_code' => $minVersion,
        ]);
    }

    public static function rateLimited(Request $request, int $retryAfter): JsonResponse
    {
        return self::make($request, 'E_RATE_LIMITED', 'Too many requests.', 429, [
            'retry_after' => $retryAfter,
        ]);
    }
}
