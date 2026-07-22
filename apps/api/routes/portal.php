<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Staff Portal — registered only when APP_ROLE=portal (ADR 0007).
 *
 * Filament mounts its own panel routes; this file holds anything outside it.
 */
Route::get('/', fn () => redirect('/staff'));

Route::get('health', fn () => response()->json(['status' => 'ok']));
