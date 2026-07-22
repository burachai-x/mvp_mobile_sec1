<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * One codebase, two containers (ADR 0007).
 *
 * APP_ROLE decides which routes exist in this process. The other side's routes
 * are never registered at all, so they 404 naturally — we do not rely on
 * middleware, which can be skipped or misordered.
 *
 * This is one of three independent layers: the API container also has no
 * DOCUMENT_KEK and no network route to Garage.
 */
$role = env('APP_ROLE', 'portal');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function () use ($role) {
            // worker and scheduler register neither: they run queue/schedule
            // commands and must not serve HTTP at all.
            if ($role === 'api') {
                Route::middleware('api')->group(__DIR__.'/../routes/api.php');
            } elseif ($role === 'portal') {
                Route::middleware('web')->group(__DIR__.'/../routes/portal.php');
            }
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
