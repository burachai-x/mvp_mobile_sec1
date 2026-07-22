<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ActivationCode;
use App\Support\ActivationToken;
use Firebase\JWT\JWT;
use RuntimeException;
use Tests\TestCase;

/**
 * The activation QR token (§6.2).
 *
 * Whoever can mint one of these can enroll a device, so the properties below
 * are the difference between "only staff issue codes" and "anyone does".
 */
final class ActivationTokenTest extends TestCase
{
    private function code(): ActivationCode
    {
        $code = new ActivationCode(['code' => 'A7K2-9QX4']);
        $code->id = '019f8b0c-0000-7000-8000-000000000001';

        return $code;
    }

    public function test_a_freshly_issued_token_verifies(): void
    {
        $service = ActivationToken::make();
        $issued = $service->issue($this->code());

        $claims = $service->verify($issued['token']);

        $this->assertSame('019f8b0c-0000-7000-8000-000000000001', $claims['jti']);
        $this->assertSame('A7K2-9QX4', $claims['code']);
    }

    public function test_only_the_hash_needs_storing(): void
    {
        $issued = ActivationToken::make()->issue($this->code());

        $this->assertSame(hash('sha256', $issued['token']), $issued['token_hash']);
        $this->assertTrue(
            ActivationToken::make()->matchesStoredHash($issued['token'], $issued['token_hash'])
        );
    }

    public function test_a_token_signed_by_another_key_is_rejected(): void
    {
        // What an attacker who has read the APK — but not the server — can do.
        $foreign = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($foreign, $foreignPem);

        $forged = JWT::encode([
            'aud' => 'enroll',
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => 'attacker-chosen',
            'code' => 'FAKE-CODE',
        ], $foreignPem, 'ES256');

        $this->expectException(RuntimeException::class);
        ActivationToken::make()->verify($forged);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $service = ActivationToken::make();
        $issued = $service->issue($this->code(), ttlMinutes: -1);

        $this->expectException(RuntimeException::class);
        $service->verify($issued['token']);
    }

    /**
     * A token issued for some other purpose must not be redeemable here even
     * though it carries a valid signature from the same key.
     */
    public function test_a_token_for_a_different_audience_is_rejected(): void
    {
        $wrongAudience = JWT::encode([
            'aud' => 'password-reset',
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => 'x',
            'code' => 'A7K2-9QX4',
        ], (string) config('security.activation_jwt_private_key'), 'ES256');

        $this->expectException(RuntimeException::class);
        ActivationToken::make()->verify($wrongAudience);
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $issued = ActivationToken::make()->issue($this->code());

        [$header, $payload, $signature] = explode('.', $issued['token']);
        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $decoded['code'] = 'SOMEONE-ELSES-CODE';
        $repacked = rtrim(strtr(base64_encode(json_encode($decoded)), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        ActivationToken::make()->verify("{$header}.{$repacked}.{$signature}");
    }

    /**
     * The api container holds the public key only (compose.yaml). Issuing must
     * be impossible there: a compromised API that could sign its own activation
     * codes would be able to enroll arbitrary devices.
     */
    public function test_issuing_without_the_private_key_is_refused(): void
    {
        config(['security.activation_jwt_private_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        ActivationToken::make()->issue($this->code());
    }

    public function test_verifying_still_works_with_only_the_public_key(): void
    {
        $issued = ActivationToken::make()->issue($this->code());

        config(['security.activation_jwt_private_key' => null]);

        $this->assertSame('A7K2-9QX4', ActivationToken::make()->verify($issued['token'])['code']);
    }
}
