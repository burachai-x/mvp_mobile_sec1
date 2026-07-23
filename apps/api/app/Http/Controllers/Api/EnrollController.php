<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\ApiError;
use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\IntegrityReport;
use App\Support\ActivationToken;
use App\Support\AttestationVerifier;
use App\Support\Crypto\Hasher;
use App\Support\PinSetupToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Device enrollment (architecture.md §6.3).
 *
 * No driver data arrives here. Staff entered it before issuing the code, so the
 * server already knows who this code belongs to — the app cannot influence it.
 */
final class EnrollController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'activation_token' => ['required', 'string'],
                'public_key' => ['required', 'string'],
                'device_uuid' => ['required', 'string', 'max:255'],
                'device_info.platform' => ['required', 'in:android,ios'],
                'device_info.model' => ['nullable', 'string', 'max:255'],
                'device_info.os_version' => ['nullable', 'string', 'max:50'],
                'key_attestation' => ['nullable', 'array'],
                'integrity' => ['nullable', 'array'],
            ]);
        } catch (ValidationException $e) {
            return ApiError::make($request, 'E_VALIDATION_FAILED', 'Request payload is not valid.', 400, [
                'details' => $e->errors(),
            ]);
        }

        // (b) Token signature, expiry and audience.
        try {
            $claims = ActivationToken::make()->verify($data['activation_token']);
        } catch (RuntimeException) {
            return $this->codeUnusable($request);
        }

        $code = ActivationCode::query()->whereKey($claims['jti'])->first();

        if ($code === null
            || ! ActivationToken::make()->matchesStoredHash($data['activation_token'], $code->token_hash)
            || ! $code->isUsable()
        ) {
            return $this->codeUnusable($request);
        }

        // (c)(d) Device health. The chain is verified up to a Google root, so
        // what comes back was signed by the device TEE. Recorded either way;
        // only blocks once KEY_ATTESTATION_ENFORCE is on (§4.2).
        //
        // The activation token doubles as the attestation challenge: it is
        // server-issued, single-use and already in the app's hands, so it binds
        // the attestation to this enrollment without an extra round trip. Without
        // a challenge, a chain captured from any genuine device would replay here.
        $assessment = (new AttestationVerifier)->assess(
            $data['key_attestation'] ?? [],
            $data['integrity'] ?? [],
            expectedChallenge: hash('sha256', $data['activation_token'], binary: true),
        );

        if ($assessment['action'] === 'block') {
            return ApiError::make($request, 'E_INTEGRITY_FAILED', 'Device integrity check failed.', 403);
        }

        // (e) One usable device per driver. Checked here as well as by the
        // partial unique index, so the app gets a clear error instead of a
        // constraint violation (§5).
        $existing = Device::usable()->where('driver_id', $code->driver_id)->exists();

        if ($existing) {
            return ApiError::make(
                $request,
                'E_DRIVER_HAS_ACTIVE_DEVICE',
                'This driver already has an active device. Staff must remove the old one first.',
                409,
            );
        }

        // (f) Everything below is one transaction: a device without its audit
        // entry, or a code marked used with no device, are both worse than a
        // failed enrollment.
        $device = DB::transaction(function () use ($data, $code, $assessment, $request): Device {
            $device = Device::create([
                'driver_id' => $code->driver_id,
                'activation_code_id' => $code->getKey(),
                'device_uuid_hmac' => Hasher::make()->hash($data['device_uuid']),
                'platform' => $data['device_info']['platform'],
                'model' => $data['device_info']['model'] ?? null,
                'os_version' => $data['device_info']['os_version'] ?? null,
                'app_version' => $request->header('X-App-Version'),
                'public_key' => $data['public_key'],
                'key_attestation' => $data['key_attestation'] ?? null,
                'status' => 'pending_pin',
                'enrolled_at' => now(),
            ]);

            $code->increment('used_count');

            IntegrityReport::create([
                'device_id' => $device->getKey(),
                'verdict' => [
                    'reported' => $data['integrity'] ?? [],
                    // Only what the chain actually proved — not the raw payload,
                    // which is the app's unverified claim and would be mistaken
                    // for evidence when someone reads this back later.
                    'attested' => $assessment['attested'],
                    'reasons' => $assessment['reasons'],
                    'chain_verified' => $assessment['chain_verified'],
                ],
                'risk_score' => $assessment['risk_score'],
                'action' => $assessment['action'],
            ]);

            AuditLog::create([
                'actor_type' => 'device',
                'actor_id' => $device->getKey(),
                'action' => 'device.enrolled',
                'subject_type' => 'driver',
                'subject_id' => $code->driver_id,
                'ip' => $request->ip(),
                'meta' => [
                    'activation_code' => $code->code,
                    'risk_score' => $assessment['risk_score'],
                ],
            ]);

            return $device;
        });

        return response()->json([
            'device_id' => $device->getKey(),
            'status' => 'pending_pin',
            'pin_setup_token' => PinSetupToken::issue($device),
            'expires_at' => PinSetupToken::expiresAt(),
        ], 201);
    }

    /**
     * One response for every unusable-code case. A caller must not be able to
     * tell "no such code" from "already used" from "expired" — that difference
     * is a probing oracle.
     */
    private function codeUnusable(Request $request): JsonResponse
    {
        return ApiError::make(
            $request,
            'E_ACTIVATION_CODE_USED',
            'Activation code is not usable.',
            409,
        );
    }
}
