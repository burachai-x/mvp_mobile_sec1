<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotating refresh tokens. Only hashes are stored.
 *
 * Reuse of an already-revoked refresh token means the token leaked: revoke every
 * session for that device, write an audit entry and alert staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('device_id');
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();

            $table->char('refresh_token_hash', 64)->unique();
            $table->uuid('access_jti')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['device_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
