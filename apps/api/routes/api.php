<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Driver app API — registered only when APP_ROLE=api (ADR 0007).
 *
 * Contract lives in docs/api/openapi.yaml; if code and spec disagree,
 * the code is wrong (CLAUDE.md §9).
 */
Route::prefix('api/v1')->group(function () {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'version' => config('app.version', '0.1.0'),
    ]));
});
