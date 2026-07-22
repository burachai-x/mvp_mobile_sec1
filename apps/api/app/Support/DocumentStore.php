<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Support\Crypto\DocumentCipher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ingest and retrieval for driver documents (architecture.md §8).
 *
 * Everything that reaches Garage is ciphertext, and the plaintext never touches
 * disk on the way there — a temp file would survive a crash and defeat the point
 * of encrypting at all.
 */
final class DocumentStore
{
    public function __construct(
        private readonly DocumentCipher $cipher,
    ) {}

    public static function make(): self
    {
        return new self(DocumentCipher::make());
    }

    public function store(Driver $driver, string $type, UploadedFile $file): DriverDocument
    {
        $plaintext = $this->readAndSanitise($file);

        $encrypted = $this->cipher->encrypt($plaintext);

        // Random key with no driver identifier in it. A key that encodes who the
        // document belongs to is itself a leak, and a guessable one defeats the
        // private bucket entirely (§8.4).
        $objectKey = sprintf('documents/%s/%s', Str::uuid(), Str::random(24));

        Storage::disk('garage')->put($objectKey, $encrypted['ciphertext']);

        return DriverDocument::create([
            'driver_id' => $driver->getKey(),
            'type' => $type,
            'object_key' => $objectKey,
            'bucket' => (string) config('filesystems.disks.garage.bucket'),
            'content_type' => $file->getClientMimeType(),
            'size_bytes' => strlen($plaintext),
            'sha256_plaintext' => $encrypted['sha256_plaintext'],
            'dek_wrapped' => $encrypted['dek_wrapped'],
            'dek_iv' => $encrypted['dek_iv'],
            'dek_tag' => $encrypted['dek_tag'],
            'iv' => $encrypted['iv'],
            'auth_tag' => $encrypted['auth_tag'],
            'kek_version' => $encrypted['kek_version'],
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function retrieve(DriverDocument $document): string
    {
        if ($document->destroyed_at !== null) {
            throw new RuntimeException('This document was destroyed by retention and cannot be recovered.');
        }

        $ciphertext = Storage::disk('garage')->get($document->object_key);

        if ($ciphertext === null) {
            throw new RuntimeException('Document object is missing from storage.');
        }

        return $this->cipher->decrypt($ciphertext, [
            'dek_wrapped' => $document->dek_wrapped,
            'dek_iv' => $document->dek_iv,
            'dek_tag' => $document->dek_tag,
            'iv' => $document->iv,
            'auth_tag' => $document->auth_tag,
            'sha256_plaintext' => $document->sha256_plaintext,
        ]);
    }

    /**
     * Destroys the wrapped DEK, which makes the stored object permanently
     * unreadable whether or not the delete from Garage succeeds (§10.3).
     *
     * The row survives so audit_logs keeps pointing somewhere real.
     */
    public function destroy(DriverDocument $document): void
    {
        $document->forceFill([
            'dek_wrapped' => null,
            'dek_iv' => null,
            'dek_tag' => null,
            'destroyed_at' => now(),
        ])->save();

        // Best effort: the bytes are already unreadable without the DEK.
        rescue(fn () => Storage::disk('garage')->delete($document->object_key), report: false);
    }

    /**
     * Validates and normalises the upload before anything else touches it.
     *
     * @throws RuntimeException when the file is not something we accept
     */
    private function readAndSanitise(UploadedFile $file): string
    {
        $maxBytes = (int) config('security.documents.max_bytes');

        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException("File exceeds the {$maxBytes} byte limit.");
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        // Detect from content, never from the declared Content-Type or the
        // filename: both are attacker-controlled (§8.3).
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = (array) config('security.documents.allowed_mime');

        if (! in_array($detected, $allowed, true)) {
            throw new RuntimeException("Rejected file type '{$detected}'.");
        }

        return match ($detected) {
            'image/jpeg', 'image/png' => $this->reencodeImage($bytes, $detected),
            'application/pdf' => $this->checkPdf($bytes),
            default => $bytes,
        };
    }

    /**
     * Re-encodes through GD, which drops EXIF along the way.
     *
     * Phone cameras write GPS coordinates into EXIF, so an ID card photo can
     * carry the driver's home address. Re-encoding also discards anything
     * appended after the image data, a common way to smuggle a payload inside a
     * file that still opens as a picture.
     */
    private function reencodeImage(string $bytes, string $mime): string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('File claims to be an image but could not be decoded.');
        }

        ob_start();

        try {
            $ok = $mime === 'image/png'
                ? imagepng($image, null, 6)
                : imagejpeg($image, null, 90);
        } finally {
            $reencoded = (string) ob_get_clean();
            imagedestroy($image);
        }

        if (! $ok || $reencoded === '') {
            throw new RuntimeException('Image could not be re-encoded.');
        }

        return $reencoded;
    }

    /**
     * PDFs cannot be re-encoded without a heavy dependency, so this only rejects
     * the obvious hazard: embedded JavaScript that fires when a viewer opens it.
     *
     * This is not full sanitisation. It is a cheap check that catches the common
     * case, and it should not be mistaken for making arbitrary PDFs safe.
     */
    private function checkPdf(string $bytes): string
    {
        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile'] as $marker) {
            if (str_contains($bytes, $marker)) {
                throw new RuntimeException("PDF contains '{$marker}' and was rejected.");
            }
        }

        return $bytes;
    }
}
