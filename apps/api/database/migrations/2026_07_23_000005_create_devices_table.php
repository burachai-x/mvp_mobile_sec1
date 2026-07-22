<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enrolled devices.
 *
 * `public_key` is the real anchor, not `device_uuid_hmac`: Android has no stable
 * hardware identifier any more, and anything the app reports can be forged.
 * The private half never leaves the device's keystore (ADR 0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('driver_id');
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();

            $table->uuid('activation_code_id');
            $table->foreign('activation_code_id')->references('id')->on('activation_codes')->restrictOnDelete();

            // HMAC only — a raw device identifier is personal data under PDPA.
            // Secondary signal for spotting cloning; not an identity anchor.
            $table->char('device_uuid_hmac', 64);

            $table->enum('platform', ['android', 'ios'])->default('android');
            $table->string('model')->nullable();
            $table->string('os_version', 50)->nullable();
            $table->string('app_version', 50)->nullable();

            // EC P-256 SPKI, base64. This is what request signatures verify against.
            $table->text('public_key');
            // Attestation chain signed by the device TEE — the only device-health
            // evidence the app cannot forge (§4.2).
            $table->jsonb('key_attestation')->nullable();

            $table->enum('status', ['pending_pin', 'active', 'blocked', 'revoked'])->default('pending_pin');

            $table->text('pin_hash')->nullable();
            $table->smallInteger('pin_failed_count')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->foreign('revoked_by')->references('id')->on('staff')->nullOnDelete();
            $table->enum('revoke_reason', ['lost', 'stolen', 'replaced', 'resigned', 'other'])->nullable();

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // "Delete device" in the staff UI is revoke + soft delete, never a real
            // DELETE: audit_logs reference device_id and we must still be able to
            // say who a lost device belonged to (§6.6).
            $table->softDeletes();
            $table->timestamps();

            $table->index('device_uuid_hmac');
            $table->index('status');
            $table->index('last_seen_at');
            $table->index('driver_id');
        });

        // One driver may hold at most one usable device. Enforced by the database,
        // not by application code, so a missed check cannot break the rule (§5).
        // Revoked devices drop out of the index, freeing the driver to re-enroll.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX devices_one_active_per_driver
            ON devices (driver_id)
            WHERE status IN ('pending_pin', 'active', 'blocked') AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
