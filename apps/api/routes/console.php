<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Retention runs on the scheduler container (compose.yaml), which holds no
 * DOCUMENT_KEK. Crypto-shredding only throws keys away, so it never needs one.
 */
Schedule::command('retention:apply')->dailyAt('03:15');
