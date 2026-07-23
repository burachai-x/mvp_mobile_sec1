<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use App\Models\Device;
use App\Support\DeviceTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 2, and the only thing here that actually authenticates a caller (ADR 0001).
 *
 * The device signs a canonical string with an EC P-256 key generated inside the
 * Android Keystore with export disabled. The private half cannot leave the
 * device even on a rooted phone, so a valid signature means the request came
 * from a device we enrolled — unlike X-App-Signature, which is a public value.
 *
 * Replay protection lives here rather than in its own middleware because the
 * nonce is part of the signed payload: splitting them would let one run without
 * the other and silently weaken both.
 */
final class VerifyDeviceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $this->resolveDeviceId($request);
        $signature = (string) $request->header('X-Device-Signature', '');
        $timestamp = (int) $request->header('X-Timestamp', '0');
        $nonce = (string) $request->header('X-Nonce', '');

        if ($deviceId === null || $signature === '' || $nonce === '' || $timestamp === 0) {
            return ApiError::deviceSignatureInvalid($request);
        }

        $maxSkew = (int) config('security.request_signature.max_skew', 60);

        // Bounded on both sides: a future-dated timestamp would otherwise let an
        // attacker mint a request that stays replayable long after the nonce
        // record expires.
        if (abs(time() - $timestamp) > $maxSkew) {
            return ApiError::deviceSignatureInvalid($request);
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->whereIn('status', ['pending_pin', 'active'])
            ->first();

        if ($device === null) {
            return ApiError::deviceSignatureInvalid($request);
        }

        if (! $this->signatureIsValid($request, $device->public_key, $signature, $timestamp, $nonce)) {
            return ApiError::deviceSignatureInvalid($request);
        }

        // Claim the nonce only after the signature checks out, so unsigned
        // traffic cannot burn nonces on a device's behalf.
        //
        // add() is atomic in Valkey; a false return means this nonce was already
        // used inside the window, i.e. a replay.
        $ttl = (int) config('security.request_signature.nonce_ttl', 300);
        $claimed = Cache::add("nonce:{$device->getKey()}:{$nonce}", 1, $ttl);

        if (! $claimed) {
            return ApiError::deviceSignatureInvalid($request);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }

    /**
     * Canonical string, matching docs/api/openapi.yaml exactly:
     *   METHOD \n PATH \n sha256_hex(body) \n timestamp \n nonce
     *
     * Hashing the body rather than signing it keeps large uploads cheap while
     * still binding the signature to the exact payload.
     */
    private function signatureIsValid(
        Request $request,
        string $publicKeyBase64,
        string $signatureBase64,
        int $timestamp,
        string $nonce,
    ): bool {
        $canonical = implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            hash('sha256', $request->getContent()),
            (string) $timestamp,
            $nonce,
        ]);

        $der = base64_decode($signatureBase64, true);
        $spki = base64_decode($publicKeyBase64, true);

        if ($der === false || $spki === false) {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }

        // Android Keystore emits ASN.1 DER ECDSA signatures, which is what
        // openssl_verify expects for EC keys.
        return openssl_verify($canonical, $der, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function resolveDeviceId(Request $request): ?string
    {
        // Route parameter first (e.g. /devices/{deviceId}/pin), then the body for
        // endpoints that carry it in the payload.
        $fromRoute = $request->route('deviceId');

        if (is_string($fromRoute) && $fromRoute !== '') {
            return $fromRoute;
        }

        $fromBody = $request->input('device_id');

        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        // Endpoints addressed as /devices/me carry the device nowhere else, so
        // the bearer token names it. Only the naming is taken from the token —
        // it selects whose public key to check against, and the signature is
        // still what authenticates. A token for one device and a signature from
        // another fails here exactly as it should.
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return null;
        }

        try {
            return DeviceTokens::make()->readAccessToken($bearer)['device_id'] ?: null;
        } catch (RuntimeException) {
            // AuthenticateDevice reports an unusable token; here it simply
            // fails to name a device.
            return null;
        }
    }
}
