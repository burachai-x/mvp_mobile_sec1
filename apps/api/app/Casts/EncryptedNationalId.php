<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Crypto\FieldCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Transparent AES-256-GCM for drivers.national_id_encrypted.
 *
 * Uses NATIONAL_ID_ENCRYPTION_KEY rather than APP_KEY so the key can be withheld
 * from the api container (ADR 0007). Reading this attribute there throws, which
 * is the intended outcome: the driver API has no business decrypting PII.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedNationalId implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return FieldCipher::forNationalId()->decrypt($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        return [$key => FieldCipher::forNationalId()->encrypt((string) $value)];
    }
}
