<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Keyed hashing for identifiers we must be able to look up but must not store.
 *
 * A plain SHA-256 would be trivially reversible here: national IDs are 13 digits
 * and device UUIDs come from a small space, so an attacker with the database
 * could rebuild both by brute force. The pepper is what prevents that, which is
 * why it lives outside the database and never rotates after go-live.
 */
final class Hasher
{
    public function __construct(private readonly ?string $pepper) {}

    public static function make(): self
    {
        return new self(config('security.device_uuid_pepper'));
    }

    /** @return string 64-char lowercase hex */
    public function hash(string $value): string
    {
        $pepper = $this->pepper;

        if ($pepper === null || $pepper === '') {
            throw new RuntimeException(
                'DEVICE_UUID_PEPPER is not set. Refusing to hash with an empty key: '
                .'the result would be reversible for 13-digit national IDs.'
            );
        }

        return hash_hmac('sha256', $value, $pepper);
    }

    /** Constant-time comparison — never use == on secrets (CLAUDE.md §6). */
    public function matches(string $value, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($value));
    }
}
