<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use RuntimeException;
use SensitiveParameter;

/**
 * Envelope encryption for driver documents (ADR 0005).
 *
 *   plaintext --AES-256-GCM(DEK)--> ciphertext  -> Garage
 *   DEK       --AES-256-GCM(KEK)--> dek_wrapped -> PostgreSQL
 *
 * A fresh DEK per file buys two things: destroying one file's key destroys only
 * that file (crypto-shredding, §10.3), and the KEK can rotate by re-wrapping
 * DEKs instead of re-encrypting every object.
 *
 * The DEK never touches disk unwrapped, and nothing here ever logs key material.
 */
final class DocumentCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LEN = 12;

    private const TAG_LEN = 16;

    private const DEK_LEN = 32;

    public function __construct(
        private readonly ?string $kek,
        private readonly int $kekVersion,
    ) {}

    public static function make(): self
    {
        $configured = config('security.document_kek');
        $kek = null;

        if (is_string($configured) && $configured !== '') {
            $raw = base64_decode($configured, true);

            if ($raw === false || strlen($raw) !== self::DEK_LEN) {
                throw new RuntimeException('DOCUMENT_KEK must be 32 raw bytes, base64-encoded.');
            }

            $kek = $raw;
        }

        return new self($kek, (int) config('security.document_kek_version', 1));
    }

    /**
     * @return array{
     *     ciphertext: string, iv: string, auth_tag: string,
     *     dek_wrapped: string, dek_iv: string, dek_tag: string,
     *     kek_version: int, sha256_plaintext: string
     * }
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): array
    {
        $kek = $this->requireKek();

        // Hash the plaintext before encrypting so integrity can be re-checked
        // after a future decrypt, independently of the GCM tag.
        $sha256 = hash('sha256', $plaintext);

        $dek = random_bytes(self::DEK_LEN);
        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $dek, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Document encryption failed.');
        }

        $dekIv = random_bytes(self::IV_LEN);
        $dekTag = '';

        $dekWrapped = openssl_encrypt(
            $dek, self::CIPHER, $kek, OPENSSL_RAW_DATA, $dekIv, $dekTag, '', self::TAG_LEN
        );

        if ($dekWrapped === false) {
            throw new RuntimeException('DEK wrapping failed.');
        }

        sodium_memzero($dek);

        return [
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'auth_tag' => $tag,
            'dek_wrapped' => $dekWrapped,
            'dek_iv' => $dekIv,
            'dek_tag' => $dekTag,
            'kek_version' => $this->kekVersion,
            'sha256_plaintext' => $sha256,
        ];
    }

    /**
     * @param  array{dek_wrapped: ?string, dek_iv: ?string, dek_tag: ?string,
     *               iv: string, auth_tag: string, sha256_plaintext?: ?string}  $meta
     */
    public function decrypt(string $ciphertext, array $meta): string
    {
        $kek = $this->requireKek();

        // Retention deletes dek_wrapped; the object bytes may still sit in Garage
        // but are permanently unreadable. Say so plainly instead of failing at
        // the crypto layer with a confusing error.
        if (($meta['dek_wrapped'] ?? null) === null) {
            throw new RuntimeException(
                'Document key was destroyed by retention: this file is unrecoverable by design.'
            );
        }

        $dek = openssl_decrypt(
            $meta['dek_wrapped'], self::CIPHER, $kek, OPENSSL_RAW_DATA,
            $meta['dek_iv'], $meta['dek_tag']
        );

        if ($dek === false) {
            throw new RuntimeException('DEK unwrapping failed: wrong KEK version or tampered row.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext, self::CIPHER, $dek, OPENSSL_RAW_DATA,
            $meta['iv'], $meta['auth_tag']
        );

        sodium_memzero($dek);

        if ($plaintext === false) {
            throw new RuntimeException('Document decryption failed: tampered ciphertext.');
        }

        $expected = $meta['sha256_plaintext'] ?? null;

        if (is_string($expected) && ! hash_equals($expected, hash('sha256', $plaintext))) {
            throw new RuntimeException('Decrypted document does not match its recorded hash.');
        }

        return $plaintext;
    }

    private function requireKek(): string
    {
        if ($this->kek === null) {
            throw new RuntimeException(
                'DOCUMENT_KEK is not available in this process. The api container '
                .'has no document access on purpose (ADR 0007) — move this work to a queued job.'
            );
        }

        return $this->kek;
    }
}
