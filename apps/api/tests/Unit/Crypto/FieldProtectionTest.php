<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Support\Crypto\FieldCipher;
use App\Support\Crypto\Hasher;
use App\Support\Masker;
use RuntimeException;
use Tests\TestCase;

/**
 * Protection of the national ID and device UUID (architecture.md §5, §9.1).
 *
 * A Thai national ID is 13 digits, so the entire space is about 10^13 — small
 * enough to enumerate. That makes the pepper, not the hash function, the thing
 * standing between a leaked database and a full list of drivers.
 */
final class FieldProtectionTest extends TestCase
{
    public function test_national_id_round_trips(): void
    {
        $cipher = FieldCipher::forNationalId();
        $stored = $cipher->encrypt('1234567890123');

        $this->assertSame('1234567890123', $cipher->decrypt($stored));
    }

    public function test_stored_value_does_not_reveal_the_number(): void
    {
        $stored = FieldCipher::forNationalId()->encrypt('1234567890123');

        $this->assertStringNotContainsString('1234567890123', $stored);
        $this->assertStringNotContainsString('1234567890123', base64_decode($stored, true) ?: '');
    }

    /**
     * Two drivers with adjacent IDs must not produce related ciphertexts, and
     * the same ID encrypted twice must differ — otherwise the column leaks
     * equality even while "encrypted".
     */
    public function test_encryption_is_not_deterministic(): void
    {
        $cipher = FieldCipher::forNationalId();

        $this->assertNotSame(
            $cipher->encrypt('1234567890123'),
            $cipher->encrypt('1234567890123'),
        );
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $cipher = FieldCipher::forNationalId();
        $stored = $cipher->encrypt('1234567890123');

        $raw = base64_decode($stored, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";

        $this->expectException(RuntimeException::class);
        $cipher->decrypt(base64_encode($raw));
    }

    /**
     * The api container is not given NATIONAL_ID_ENCRYPTION_KEY (ADR 0007), so
     * accidental PII access there must fail with a message that points at the
     * design rather than a generic openssl error.
     */
    public function test_missing_key_explains_the_container_split(): void
    {
        config(['security.national_id_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ADR 0007/');

        FieldCipher::forNationalId()->encrypt('1234567890123');
    }

    public function test_hash_is_stable_and_distinct(): void
    {
        $hasher = Hasher::make();

        $this->assertSame($hasher->hash('device-a'), $hasher->hash('device-a'));
        $this->assertNotSame($hasher->hash('device-a'), $hasher->hash('device-b'));
        $this->assertSame(64, strlen($hasher->hash('device-a')));
    }

    public function test_hash_comparison_accepts_only_the_right_value(): void
    {
        $hasher = Hasher::make();
        $expected = $hasher->hash('1234567890123');

        $this->assertTrue($hasher->matches('1234567890123', $expected));
        $this->assertFalse($hasher->matches('1234567890124', $expected));
    }

    /**
     * Without a pepper this degrades to plain SHA-256, which for a 13-digit
     * input is reversible by brute force in minutes. Refusing is the only safe
     * behaviour; defaulting to an empty key would fail silently and look fine.
     */
    public function test_hashing_without_a_pepper_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reversible/');

        (new Hasher(null))->hash('1234567890123');
    }

    public function test_masking_keeps_only_the_agreed_characters(): void
    {
        $this->assertSame('x-xxxx-xxxxx-x2-3', Masker::nationalId('1234567890123'));
        $this->assertSame('08x-xxx-5678', Masker::phone('0812345678'));
        $this->assertSame('xxxxxx6789', Masker::tail('1234556789'));
    }

    public function test_masking_never_returns_the_full_value(): void
    {
        foreach (['1234567890123', '9876543210987'] as $id) {
            $this->assertStringNotContainsString($id, (string) Masker::nationalId($id));
        }
    }
}
