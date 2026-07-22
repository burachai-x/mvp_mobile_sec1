<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail.
 *
 * No foreign keys on actor/subject on purpose: entries must survive the removal
 * of whatever they describe, and actors span several tables.
 *
 * PII-access entries (driver.pii_viewed, document.viewed) must outlive the data
 * they describe — otherwise we cannot answer "who looked at this driver's file?"
 * which is exactly what PDPA requires (§9.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->enum('actor_type', ['staff', 'driver', 'device', 'system']);
            $table->uuid('actor_id')->nullable();

            $table->string('action', 100);
            $table->string('subject_type', 50)->nullable();
            $table->uuid('subject_id')->nullable();

            $table->string('ip', 45)->nullable();
            // Reason, fields revealed, elevation_id — never raw PII or secrets.
            $table->jsonb('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // Database-level guarantee, so a stray Eloquent call cannot rewrite history.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not allowed', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only()');
        Schema::dropIfExists('audit_logs');
    }
};
