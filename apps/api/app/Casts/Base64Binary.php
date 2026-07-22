<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw bytes in the application, base64 in the column.
 *
 * Keys, IVs and GCM tags are arbitrary binary. Eloquent binds parameters as
 * strings and PostgreSQL rejects anything that is not valid UTF-8, so storing
 * them directly fails on roughly half of all random values — intermittently,
 * which is worse than failing every time.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Base64Binary implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = base64_decode((string) $value, true);

        return $decoded === false ? null : $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value === null ? null : base64_encode((string) $value)];
    }
}
