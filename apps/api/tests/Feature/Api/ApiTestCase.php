<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ActivationCode;
use App\Models\Driver;
use App\Models\Staff;
use App\Support\ActivationToken;
use App\Support\Crypto\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * Shared setup for the driver app API (architecture.md §6.3, §6.4).
 *
 * Every helper here builds a request the way a real device would: the app
 * signature header, the app version header, and — where the endpoint demands it
 * — an ECDSA P-256 signature over the exact bytes being sent. Tests that want to
 * break one of those properties override exactly one argument, so what is under
 * test stays obvious.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /** Stand-in for an APK signing certificate fingerprint; the value is public by nature. */
    protected const APP_SIGNATURE = 'aa11bb22cc33dd44ee55ff6677889900aabbccddeeff00112233445566778899';

    protected const APP_VERSION = '1.4.2+142';

    protected Staff $staff;

    private OpenSSLAsymmetricKey $deviceKey;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite boots with APP_ROLE=portal, where routes/api.php is never
        // registered at all (ADR 0007) — that separation is asserted by
        // AppRoleIsolationTest and must stay untouched. Registering the same file
        // on the live router gives these tests the real routes, middleware and
        // throttles without changing how the application boots.
        Route::middleware('api')->group(base_path('routes/api.php'));

        // The cache store comes from phpunit.xml (CACHE_STORE=array, force="true"):
        // it has to be set before the app boots, because RateLimiter captures its
        // store the moment it is first resolved. Overriding cache.default here
        // instead would come too late and leave the throttles writing to the
        // shared Valkey instance, where counters survive between runs.
        config([
            'security.app_signature_allowlist' => [self::APP_SIGNATURE],
        ]);

        $this->staff = new Staff(['email' => 'registrar-'.Str::random(8).'@test.local', 'role' => 'registrar']);
        $this->staff->password_hash = Hash::make('irrelevant');
        $this->staff->save();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 test key.');
        }

        $this->deviceKey = $key;
    }

    protected function newDriver(): Driver
    {
        // Fabricated digits only — real driver data must never reach a fixture
        // (CLAUDE.md §8).
        $nationalId = (string) random_int(1000000000000, 9999999999999);

        return Driver::create([
            'full_name' => 'Test Driver',
            'employee_code' => 'EMP-'.Str::upper(Str::random(8)),
            'national_id_encrypted' => 'ciphertext-placeholder',
            'national_id_hmac' => Hasher::make()->hash($nationalId),
            'phone' => '0800000000',
            'created_by' => $this->staff->getKey(),
        ]);
    }

    /**
     * Mirrors what staff do in the portal: create the row, then store only
     * sha256(jwt) of the token that was handed out.
     *
     * @return array{0: ActivationCode, 1: string} the row and the raw QR token
     */
    protected function issueActivationCode(?Driver $driver = null, int $ttlMinutes = 15): array
    {
        $driver ??= $this->newDriver();

        $code = ActivationCode::create([
            'code' => Str::upper(Str::random(10)),
            'token_hash' => str_repeat('0', 64),
            'driver_id' => $driver->getKey(),
            'created_by' => $this->staff->getKey(),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'max_uses' => 1,
        ]);

        $issued = ActivationToken::make()->issue($code, $ttlMinutes);
        $code->update(['token_hash' => $issued['token_hash']]);

        return [$code->refresh(), $issued['token']];
    }

    /** @param  array<string, string>  $extra */
    protected function appHeaders(array $extra = []): array
    {
        return array_merge([
            'X-App-Signature' => self::APP_SIGNATURE,
            'X-App-Version' => self::APP_VERSION,
        ], $extra);
    }

    /** @param  array<string, string>  $headers */
    protected function enroll(string $activationToken, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/devices/enroll', [
            'activation_token' => $activationToken,
            'public_key' => $this->devicePublicKey(),
            'device_uuid' => 'android-id-'.Str::random(16),
            'device_info' => ['platform' => 'android', 'model' => 'Pixel 7a', 'os_version' => '14'],
        ], $this->appHeaders($headers));
    }

    /** @return array{0: string, 1: string} device id and its single-use PIN setup token */
    protected function enrollDevice(): array
    {
        [, $token] = $this->issueActivationCode();

        $response = $this->enroll($token);
        $response->assertCreated();

        return [(string) $response->json('device_id'), (string) $response->json('pin_setup_token')];
    }

    /** Enrolls a device and takes it all the way to `active` with a known PIN. */
    protected function activeDevice(string $pin): string
    {
        [$deviceId, $setupToken] = $this->enrollDevice();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => $pin,
        ])->assertOk();

        return $deviceId;
    }

    /**
     * Signs and sends a request the way the app does.
     *
     * @param  array<string, mixed>  $payload  the body the signature commits to
     * @param  string|null  $sentBody  raw body actually transmitted, when it has
     *                                 to differ from the signed one
     * @param  array<string, string>  $headers
     */
    protected function signedPost(
        string $uri,
        array $payload,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $sentBody = null,
        array $headers = [],
    ): TestResponse {
        $timestamp ??= time();
        $nonce ??= (string) Str::uuid();
        $signedBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $server = $this->transformHeadersToServerVars($this->appHeaders(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Device-Signature' => $this->signCanonical('POST', $uri, $signedBody, $timestamp, $nonce),
        ], $headers)));

        return $this->call('POST', $uri, [], [], [], $server, $sentBody ?? $signedBody);
    }

    /** EC P-256 SPKI in DER, base64 — the form devices.public_key stores. */
    protected function devicePublicKey(): string
    {
        $details = openssl_pkey_get_details($this->deviceKey);
        $pem = is_array($details) ? (string) ($details['key'] ?? '') : '';

        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /** METHOD \n PATH \n sha256_hex(body) \n timestamp \n nonce, per openapi.yaml. */
    private function signCanonical(string $method, string $path, string $body, int $timestamp, string $nonce): string
    {
        $canonical = implode("\n", [
            $method,
            $path,
            hash('sha256', $body),
            (string) $timestamp,
            $nonce,
        ]);

        openssl_sign($canonical, $signature, $this->deviceKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
