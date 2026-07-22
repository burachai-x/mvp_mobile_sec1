<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use RuntimeException;
use SensitiveParameter;

/**
 * AES-256-GCM for individual database fields (currently the national ID).
 *
 * GCM is an AEAD, so tampering with stored ciphertext is detected rather than
 * silently decrypting to garbage. CBC and other unauthenticated modes are not
 * an option here (CLAUDE.md §6).
 */
final class FieldCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LEN = 12;   // GCM standard; other lengths weaken the mode

    private const TAG_LEN = 16;

    public function __construct(private readonly ?string $key) {}

    public static function forNationalId(): self
    {
        return new self(self::decodeKey(config('security.national_id_key')));
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $key = $this->requireKey();
        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Field encryption failed.');
        }

        // iv|tag|ciphertext, base64 — self-describing so no extra columns needed
        return base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $stored): string
    {
        $key = $this->requireKey();
        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Stored ciphertext is malformed.');
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($plaintext === false) {
            // Either the key is wrong or the row was tampered with. Both are
            // incidents; do not fall back to returning anything.
            throw new RuntimeException('Field decryption failed: wrong key or tampered ciphertext.');
        }

        return $plaintext;
    }

    private function requireKey(): string
    {
        if ($this->key === null || $this->key === '') {
            throw new RuntimeException(
                'NATIONAL_ID_ENCRYPTION_KEY is not available in this process. '
                .'The api container is not meant to touch driver PII (ADR 0007).'
            );
        }

        return $this->key;
    }

    private static function decodeKey(?string $configured): ?string
    {
        if ($configured === null || $configured === '') {
            return null;
        }

        $raw = base64_decode($configured, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('Encryption key must be 32 raw bytes, base64-encoded.');
        }

        return $raw;
    }
}
