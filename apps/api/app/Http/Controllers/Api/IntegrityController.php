<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\ApiError;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\IntegrityReport;
use App\Support\AttestationVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the device says about itself, every time the app opens
 * (docs/api/openapi.yaml `/devices/me/integrity`).
 *
 * Everything here is forgeable — Magisk hides root, Frida makes a check return
 * false, a repackaged APK deletes it. It is collected because otherwise nobody
 * would know how often devices in the fleet look tampered with, and because the
 * app tells the driver what it found, which is only honest if someone is also
 * keeping the record.
 *
 * The device does not get to decide from this. The server answers with what it
 * wants done, and the decision that carries weight comes from key attestation
 * at enrollment, which the TEE signs (§4.2).
 */
final class IntegrityController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $signals = $request->validate([
                'rooted' => ['nullable', 'boolean'],
                'su_binary_found' => ['nullable', 'boolean'],
                'test_keys' => ['nullable', 'boolean'],
                'developer_options' => ['nullable', 'boolean'],
                'usb_debugging' => ['nullable', 'boolean'],
                'wireless_debugging' => ['nullable', 'boolean'],
                'emulator' => ['nullable', 'boolean'],
                'mock_location' => ['nullable', 'boolean'],
                'debugger_attached' => ['nullable', 'boolean'],
                'hook_framework_detected' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return ApiError::make($request, 'E_VALIDATION_FAILED', 'Request payload is not valid.', 400, [
                'details' => $e->errors(),
            ]);
        }

        /** @var Device $device */
        $device = $request->attributes->get('device');

        // Only the self-reported flags. Attestation belongs to enrollment,
        // where a challenge binds it to that moment; judging a chain here would
        // mean accepting one the app could replay from its own past, and
        // penalising its absence would mark every ordinary launch as risky.
        $assessment = (new AttestationVerifier)->assessSelfReported($signals);

        $report = IntegrityReport::create([
            'device_id' => $device->getKey(),
            'verdict' => [
                'reported' => $signals,
                'attested' => [],
                'reasons' => $assessment['reasons'],
                'chain_verified' => false,
            ],
            'risk_score' => $assessment['risk_score'],
            'action' => $assessment['action'],
        ]);

        // Only when something was actually flagged. An audit row for every
        // launch of every device would bury the entries that matter, and this
        // table is kept longer than the data it describes (§6).
        if ($assessment['reasons'] !== []) {
            AuditLog::create([
                'actor_type' => 'device',
                'actor_id' => $device->getKey(),
                'action' => 'device.integrity_flagged',
                'subject_type' => 'device',
                'subject_id' => $device->getKey(),
                'ip' => $request->ip(),
                'meta' => [
                    'reasons' => $assessment['reasons'],
                    'risk_score' => $assessment['risk_score'],
                    'report_id' => $report->getKey(),
                ],
            ]);
        }

        return response()->json(['action' => $assessment['action']], 202);
    }
}
