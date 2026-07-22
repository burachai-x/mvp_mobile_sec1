<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata for the encrypted documents held in Garage (ADR 0004, 0005).
 * The object bytes are ciphertext; the file-specific DEK lives here, wrapped
 * by the KEK. Deleting `dek_wrapped` renders the object unrecoverable forever —
 * that is exactly how retention deletion works (§10.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();

            $table->enum('type', ['application_form', 'national_id_copy', 'driver_license_copy']);

            // Random key. Must never contain a name, national ID or a sequence
            // number — a guessable key is a leak even with a private bucket.
            $table->text('object_key');
            $table->string('bucket', 100);

            // Of the plaintext, so integrity can be checked after decryption.
            $table->char('sha256_plaintext', 64);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('size_bytes');

            // Envelope encryption, AES-256-GCM (ADR 0005).
            //
            // Stored base64 in text columns rather than bytea: Eloquent binds
            // parameters as strings, and PostgreSQL rejects raw AES output as
            // invalid UTF-8 before it ever reaches a bytea column. Making this
            // work with bytea needs PDO::PARAM_LOB on every write, which is easy
            // to forget once and hard to notice. These values are 12-48 bytes,
            // so the base64 overhead is irrelevant.
            // The Base64Binary cast keeps the model API binary in / binary out.
            $table->text('dek_wrapped')->nullable();
            $table->text('dek_iv')->nullable();
            $table->text('dek_tag')->nullable();
            $table->text('iv');
            $table->text('auth_tag');
            // Lets the KEK rotate without re-encrypting every object.
            $table->smallInteger('kek_version')->default(1);

            $table->uuid('uploaded_by');
            $table->foreign('uploaded_by')->references('id')->on('staff')->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();
            // When the DEK was destroyed by retention (crypto-shredding).
            $table->timestamp('destroyed_at')->nullable();

            $table->unique(['driver_id', 'type']);
            $table->index('destroyed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
