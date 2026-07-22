<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers. There is no self-registration: applicants submit paper documents,
 * staff verify them, and only then enter the record (architecture.md §6.1).
 * A row existing here already means "approved" — `created_by` is the evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('employee_code', 50)->unique();

            // National ID is encrypted at rest; the HMAC exists only so we can
            // look up / dedupe without decrypting. Never store plaintext (PDPA).
            $table->text('national_id_encrypted');
            $table->char('national_id_hmac', 64)->unique();

            $table->string('phone', 20);
            $table->string('license_number', 50)->nullable();
            $table->date('license_expires_at')->nullable();

            $table->enum('status', ['active', 'suspended', 'terminated'])->default('active');

            // Staff member who verified the paper documents and entered this record.
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('staff')->restrictOnDelete();

            // Retention clock starts here (§10.3)
            $table->timestamp('terminated_at')->nullable();
            // Set once crypto-shredding has destroyed this driver's PII.
            // The row itself stays so audit_logs references remain valid.
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('terminated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
