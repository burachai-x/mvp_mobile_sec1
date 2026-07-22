<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects app builds older than MIN_SUPPORTED_APP_VERSION with 426 so the app
 * can force an update through the signed manifest (§11.3).
 *
 * Compares versionCode, not the display version: the manifest's rollback
 * protection is built on versionCode being monotonic (ADR 0006).
 */
final class EnforceMinAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $min = (int) config('security.min_supported_app_version', 1);

        // "1.4.2+142" -> 142
        $header = (string) $request->header('X-App-Version', '');
        $presented = str_contains($header, '+')
            ? (int) substr($header, strrpos($header, '+') + 1)
            : 0;

        // A missing or unparseable header is not treated as too-old: bouncing
        // every such request would break the app the moment the header format
        // changes. Signature checks still apply.
        if ($presented > 0 && $presented < $min) {
            return ApiError::updateRequired($request, (string) $min);
        }

        return $next($request);
    }
}
