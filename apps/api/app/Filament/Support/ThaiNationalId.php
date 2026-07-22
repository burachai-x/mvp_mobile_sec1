<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Thai national ID: 13 digits where the last one is a mod-11 check digit.
 *
 * Staff type these off a paper form, so a transposed digit is the expected
 * mistake. The checksum catches it before the value is encrypted and becomes
 * expensive to compare against.
 */
final class ThaiNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{13}$/', $value) !== 1) {
            $fail('The national ID must be exactly 13 digits.');

            return;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $value[$i] * (13 - $i);
        }

        if ((11 - ($sum % 11)) % 10 !== (int) $value[12]) {
            $fail('The national ID checksum is invalid. Please re-check the number.');
        }
    }
}
