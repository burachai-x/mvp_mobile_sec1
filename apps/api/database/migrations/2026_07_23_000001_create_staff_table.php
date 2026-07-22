<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff — office users who register drivers and issue activation codes.
 *
 * `data_access_pin_hash` is a SECOND factor, separate from the login password.
 * It gates viewing unmasked PII and downloading driver documents (architecture.md §9.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->enum('role', ['admin', 'registrar']);

            // TOTP is mandatory: this account can issue activation codes and
            // reset any driver's PIN, so a leaked password alone must not be enough.
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();

            // Step-up PIN — Argon2id, never logged (§9.2)
            $table->text('data_access_pin_hash')->nullable();
            $table->smallInteger('pin_failed_count')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // Having the PIN is not enough; the account also needs the permission.
            $table->boolean('can_view_pii')->default(false);
            $table->boolean('can_download_documents')->default(false);

            $table->timestamp('last_login_at')->nullable();
            // Soft-disable only — deleting would orphan audit_logs.actor_id
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
