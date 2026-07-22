<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * CLAUDE.md §6 forbids any endpoint from returning pin_hash, token_hash,
 * totp_secret, national_id_encrypted or object_key.
 *
 * Relying on every controller and Filament resource to remember that is how
 * these things leak. Declaring them in $hidden makes the model refuse by
 * default, and this test is what keeps the declaration from quietly rotting
 * when someone adds a column.
 */
final class NoSecretLeakTest extends TestCase
{
    /**
     * @return array<string, array{class-string<Model>, list<string>}>
     */
    public static function secretsPerModel(): array
    {
        return [
            'staff' => [Staff::class, ['password_hash', 'totp_secret', 'data_access_pin_hash']],
            'driver' => [Driver::class, ['national_id_encrypted', 'national_id_hmac']],
            'device' => [Device::class, ['pin_hash']],
            'device session' => [DeviceSession::class, ['refresh_token_hash']],
            'activation code' => [ActivationCode::class, ['token_hash']],
            // object_key is a secret in its own right: a guessable or leaked key
            // is enough to fetch the object even though the bucket is private.
            'driver document' => [DriverDocument::class, [
                'object_key', 'dek_wrapped', 'dek_iv', 'dek_tag', 'iv', 'auth_tag',
            ]],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $secrets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretsPerModel')]
    public function test_secret_columns_are_hidden_from_serialisation(string $modelClass, array $secrets): void
    {
        $hidden = (new $modelClass())->getHidden();

        foreach ($secrets as $column) {
            $this->assertContains(
                $column,
                $hidden,
                "{$modelClass}::\$hidden must contain '{$column}' — otherwise toArray() and "
                ."any JSON response leak it (CLAUDE.md §6)."
            );
        }
    }

    /**
     * $hidden is only honoured by toArray()/toJson(). Confirm the actual output
     * is clean rather than trusting the declaration alone.
     */
    public function test_serialising_a_populated_model_omits_the_secrets(): void
    {
        $device = new Device([
            'device_uuid_hmac' => str_repeat('a', 64),
            'public_key' => 'PUBLIC-KEY-MATERIAL',
        ]);
        $device->pin_hash = '$argon2id$v=19$m=65536,t=3,p=4$SECRET';

        $serialised = json_encode($device->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SECRET', $serialised);
        $this->assertStringNotContainsString('argon2id', $serialised);
        // The public key is not a secret and stays visible.
        $this->assertStringContainsString('PUBLIC-KEY-MATERIAL', $serialised);
    }

    public function test_driver_serialisation_never_carries_the_national_id(): void
    {
        $driver = new Driver([
            'full_name' => 'Somchai Test',
            'employee_code' => 'EMP001',
            'phone' => '0812345678',
        ]);
        $driver->national_id_encrypted = '1234567890123';
        $driver->national_id_hmac = str_repeat('b', 64);

        $serialised = json_encode($driver->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('1234567890123', $serialised);
        $this->assertStringNotContainsString(str_repeat('b', 64), $serialised);
    }

    /**
     * `national_id` is not a column, so assigning it stores an unmapped
     * attribute that bypasses the cast entirely. Easy mistake, and it would put
     * the plaintext straight into JSON — so the name is hidden as well.
     */
    public function test_assigning_the_wrong_attribute_name_still_does_not_leak(): void
    {
        $driver = new Driver(['full_name' => 'Somchai Test']);
        $driver->national_id = '1234567890123';

        $this->assertStringNotContainsString(
            '1234567890123',
            json_encode($driver->toArray(), JSON_THROW_ON_ERROR),
        );
    }
}
