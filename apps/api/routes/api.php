<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\IntegrityController;
use App\Http\Controllers\Api\PinController;
use App\Http\Controllers\Api\RefreshController;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnforceMinAppVersion;
use App\Http\Middleware\VerifyAppSignature;
use App\Http\Middleware\VerifyDeviceSignature;
use Illuminate\Support\Facades\Route;

/**
 * Driver app API — registered only when APP_ROLE=api (ADR 0007).
 *
 * Contract lives in docs/api/openapi.yaml; if code and spec disagree,
 * the code is wrong (CLAUDE.md §9).
 */
Route::prefix('api/v1')
    ->middleware([VerifyAppSignature::class, EnforceMinAppVersion::class])
    ->group(function () {
        Route::get('health', fn () => response()->json([
            'status' => 'ok',
            'version' => config('app.version', '0.1.0'),
        ]));

        // No device signature yet: the keypair is created during this call, so
        // the server has nothing to verify against until it completes.
        Route::post('devices/enroll', EnrollController::class)
            ->middleware('throttle:enroll');

        // Signed with the key from enrollment, which is what proves the caller
        // is the device that just enrolled.
        Route::post('devices/{deviceId}/pin', [PinController::class, 'store'])
            ->middleware([VerifyDeviceSignature::class, 'throttle:pin-setup']);

        Route::post('auth/pin/verify', [PinController::class, 'verify'])
            ->middleware([VerifyDeviceSignature::class, 'throttle:pin-verify']);

        // Unlocking with a fingerprint spends a refresh token here rather than a
        // PIN, so this is on the same footing as pin/verify: signed by the
        // device key, rate limited, and the server decides.
        Route::post('auth/refresh', RefreshController::class)
            ->middleware([VerifyDeviceSignature::class, 'throttle:token-refresh']);

        // Reported on every launch, so it needs an unlocked device rather than
        // just a signature: the signature says which phone, the token says
        // someone got past the PIN on it.
        Route::post('devices/me/integrity', IntegrityController::class)
            ->middleware([VerifyDeviceSignature::class, AuthenticateDevice::class, 'throttle:integrity']);
    });
