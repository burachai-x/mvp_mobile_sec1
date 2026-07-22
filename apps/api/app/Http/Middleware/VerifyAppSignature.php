<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of the trust model: X-App-Signature against an allowlist (PRD §2.3).
 *
 * This proves very little. The signing certificate's SHA-256 is public — anyone
 * can read it out of a published APK with `apksigner verify --print-certs` — so
 * it is a signal, never authentication. What actually authenticates a caller is
 * the hardware-backed device key checked in VerifyDeviceSignature (ADR 0001).
 *
 * Kept because PRD §2.3 requires it and it does filter out lazy scripted traffic.
 */
final class VerifyAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowlist */
        $allowlist = config('security.app_signature_allowlist', []);

        // An empty allowlist means the deployment has not been configured yet.
        // Failing open here would silently disable the check in production, so
        // allow it only outside production.
        if ($allowlist === []) {
            if (app()->environment('production')) {
                return ApiError::appSignatureInvalid($request);
            }

            return $next($request);
        }

        $presented = (string) $request->header('X-App-Signature', '');

        foreach ($allowlist as $allowed) {
            // Several fingerprints are accepted at once so a signing-key rotation
            // does not 403 every installed device on release day (§6.8).
            if (hash_equals(strtolower($allowed), strtolower($presented))) {
                return $next($request);
            }
        }

        return ApiError::appSignatureInvalid($request);
    }
}
