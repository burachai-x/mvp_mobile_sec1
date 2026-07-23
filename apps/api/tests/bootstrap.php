<?php

declare(strict_types=1);

/**
 * Test environment normalisation.
 *
 * phpunit.xml's <env force="true"> only reaches putenv(); it leaves $_SERVER
 * alone when the variable is already there. Docker Compose sets these in
 * $_SERVER, and Laravel's Env reads $_SERVER first — so the phpunit values were
 * being ignored and the whole suite ran against the development Valkey and the
 * development database.
 *
 * Two things went wrong because of that: rate-limit counters and nonces
 * survived between tests and between runs, making replay assertions depend on
 * whatever ran earlier; and RefreshDatabase wiped the dev database on every
 * `make test`.
 *
 * This has to happen before the framework boots, which is why it lives here
 * rather than in a base TestCase.
 */
$overrides = [
    // Without this Laravel reports APP_ENV=local and app()->runningUnitTests()
    // is false. Every Filament testing helper is guarded by that check and
    // silently becomes a no-op — fillForm(), assertFormSet() and callAction()
    // included — so tests using them pass without asserting anything at all.
    'APP_ENV' => 'testing',

    // Never the shared Valkey: nonces, PIN setup tokens and rate-limit counters
    // must not outlive the test that created them.
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',

    // Postgres is required — the schema uses jsonb, a partial unique index and
    // an append-only trigger, none of which sqlite has — but never the dev
    // database, which RefreshDatabase would truncate on every run.
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'mvp_test',
];

foreach ($overrides as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
