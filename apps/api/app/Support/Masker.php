<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masking for personal data (§9.1).
 *
 * This runs at the API/serialization layer, never in the frontend: masking in
 * the browser means the full value was already sent and anyone can read it in
 * devtools, which is not masking at all.
 */
final class Masker
{
    /** 1234567890123 -> x-xxxx-xxxxx-x2-3 */
    public static function nationalId(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $last = substr($value, -2);

        return 'x-xxxx-xxxxx-x'.$last[0].'-'.$last[1];
    }

    /** 0812345678 -> 08x-xxx-5678 */
    public static function phone(?string $value): ?string
    {
        if ($value === null || strlen($value) < 4) {
            return $value;
        }

        return substr($value, 0, 2).'x-xxx-'.substr($value, -4);
    }

    /** Keeps the last 4 characters of anything else (licence numbers, tokens). */
    public static function tail(?string $value, int $keep = 4): ?string
    {
        if ($value === null || strlen($value) <= $keep) {
            return $value === null ? null : str_repeat('x', strlen($value));
        }

        return str_repeat('x', strlen($value) - $keep).substr($value, -$keep);
    }
}
