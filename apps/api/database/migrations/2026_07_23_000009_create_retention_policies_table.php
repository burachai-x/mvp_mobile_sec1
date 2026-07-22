<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention windows, editable by an admin through the portal (§10).
 *
 * The allowed range is a CHECK constraint rather than a configurable field:
 * typing 3 instead of 30 would destroy almost every driver record, and
 * crypto-shredding makes that unrecoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->integer('value_days');

            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE retention_policies ADD CONSTRAINT retention_days_sane
             CHECK (value_days >= 7 AND value_days <= 3650)'
        );

        // Defaults so the system is usable immediately; admins tune them later.
        DB::table('retention_policies')->insert([
            ['key' => 'activity_log_days', 'value_days' => 90],
            ['key' => 'integrity_report_days', 'value_days' => 90],
            ['key' => 'driver_data_after_termination_days', 'value_days' => 30],
            ['key' => 'document_after_termination_days', 'value_days' => 30],
            ['key' => 'pii_access_log_days', 'value_days' => 730],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
