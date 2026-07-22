<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\PinController;
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
            ->middleware('throttle:10,60');

        // Signed with the key from enrollment, which is what proves the caller
        // is the device that just enrolled.
        Route::post('devices/{deviceId}/pin', [PinController::class, 'store'])
            ->middleware([VerifyDeviceSignature::class, 'throttle:5,60']);

        Route::post('auth/pin/verify', [PinController::class, 'verify'])
            ->middleware([VerifyDeviceSignature::class, 'throttle:10,1']);
    });
