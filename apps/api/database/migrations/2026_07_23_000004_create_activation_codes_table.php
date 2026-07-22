<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time activation codes. `driver_id` is mandatory: every code is issued to
 * a specific, already-registered driver — there are no floating codes (§6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human-readable, ambiguous characters (0/O, 1/I/l) removed.
            $table->string('code', 16)->unique();

            // Only the hash of the QR JWT is stored, never the token itself.
            $table->char('token_hash', 64);

            $table->uuid('driver_id');
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();

            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('staff')->restrictOnDelete();

            $table->timestamp('expires_at');
            $table->smallInteger('max_uses')->default(1);
            $table->smallInteger('used_count')->default(0);

            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->foreign('revoked_by')->references('id')->on('staff')->nullOnDelete();

            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_codes');
    }
};
