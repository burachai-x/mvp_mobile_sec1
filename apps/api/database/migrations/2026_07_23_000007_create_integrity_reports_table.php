<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device-health reports sent on every app launch.
 *
 * Everything in `verdict` that came from the app itself is forgeable (Magisk
 * hides root, Frida rewrites the check). It is kept as evidence and for trend
 * analysis — the decision to block must never rest on it alone (§4.1).
 * The trustworthy part is the attestation stored on devices.key_attestation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('device_id');
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();

            $table->jsonb('verdict');
            $table->smallInteger('risk_score')->default(0);
            // Server's decision, which the app must obey instead of its own check.
            $table->enum('action', ['allow', 'warn', 'block'])->default('allow');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_reports');
    }
};
