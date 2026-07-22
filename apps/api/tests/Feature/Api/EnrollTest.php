<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\IntegrityReport;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Device enrollment (architecture.md §6.3, openapi.yaml /devices/enroll).
 *
 * Enrollment is the one call that turns a public activation code into a
 * long-lived credential, so every rejection path here is what stands between a
 * stolen or guessed code and a working device.
 */
final class EnrollTest extends ApiTestCase
{
    public function test_a_valid_activation_token_enrolls_a_device(): void
    {
        [$code, $token] = $this->issueActivationCode();

        $response = $this->enroll($token);

        $response->assertCreated()
            ->assertJsonPath('status', 'pending_pin')
            ->assertJsonStructure(['device_id', 'status', 'pin_setup_token', 'expires_at']);

        $device = Device::findOrFail($response->json('device_id'));

        $this->assertSame('pending_pin', $device->status);
        $this->assertSame($code->driver_id, $device->driver_id);
        // Spending the code is what makes it single-use; without this the same QR
        // enrolls a second device.
        $this->assertSame(1, $code->refresh()->used_count);
    }

    public function test_an_activation_code_cannot_be_redeemed_twice(): void
    {
        [, $token] = $this->issueActivationCode();

        $this->enroll($token)->assertCreated();

        $this->enroll($token)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'E_ACTIVATION_CODE_USED');
    }

    /**
     * An expired code and a forged one must produce byte-identical responses.
     * Any difference tells an attacker which half of the token to keep working
     * on, which is exactly the signal ActivationToken::verify collapses.
     */
    public function test_an_expired_token_is_indistinguishable_from_a_forged_one(): void
    {
        [, $expiredToken] = $this->issueActivationCode(ttlMinutes: -1);
        [$code] = $this->issueActivationCode();

        $forgedToken = JWT::encode([
            'aud' => 'enroll',
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => (string) $code->getKey(),
            'code' => $code->code,
        ], $this->foreignSigningKey(), 'ES256');

        $expired = $this->enroll($expiredToken);
        $forged = $this->enroll($forgedToken);

        $expired->assertStatus(409)->assertJsonPath('error.code', 'E_ACTIVATION_CODE_USED');

        $this->assertSame($expired->status(), $forged->status());
        $this->assertSame($expired->json(), $forged->json());

        // A token that failed verification must not have touched the row it named.
        $this->assertSame(0, $code->refresh()->used_count);
    }

    public function test_a_driver_with_a_usable_device_cannot_enroll_another(): void
    {
        $driver = $this->newDriver();

        [, $first] = $this->issueActivationCode($driver);
        $this->enroll($first)->assertCreated();

        [, $second] = $this->issueActivationCode($driver);

        $this->enroll($second)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'E_DRIVER_HAS_ACTIVE_DEVICE');

        // One driver, one device (§5): a second row would also have violated the
        // partial unique index, but the app must get a readable error instead.
        $this->assertSame(1, Device::where('driver_id', $driver->getKey())->count());
    }

    public function test_an_app_signature_outside_the_allowlist_is_rejected(): void
    {
        [, $token] = $this->issueActivationCode();

        $this->enroll($token, ['X-App-Signature' => str_repeat('0', 64)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'E_APP_SIGNATURE_INVALID');
    }

    public function test_an_app_older_than_the_minimum_version_is_rejected(): void
    {
        // Read through config, not env(): the middleware has to keep working
        // under `php artisan config:cache`, where .env is never loaded at runtime.
        config(['security.min_supported_app_version' => 142]);

        [, $token] = $this->issueActivationCode();

        $this->enroll($token, ['X-App-Version' => '1.4.1+141'])
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'E_APP_UPDATE_REQUIRED')
            ->assertJsonPath('error.min_supported_version_code', '142');
    }

    /**
     * A versionCode the server cannot parse is deliberately let through: bouncing
     * it would brick every installed app the day the header format changes, and
     * the signature layers still apply.
     */
    public function test_a_missing_app_version_header_is_allowed_through(): void
    {
        // Read through config, not env(): the middleware has to keep working
        // under `php artisan config:cache`, where .env is never loaded at runtime.
        config(['security.min_supported_app_version' => 142]);

        [, $token] = $this->issueActivationCode();

        $this->enroll($token, ['X-App-Version' => ''])->assertCreated();
    }

    /**
     * The audit entry is how staff can later answer "when did this device appear
     * and against whose code?", and the integrity report is the evidence for the
     * monitor-mode attestation rollout (§4.2). Both are written inside the same
     * transaction as the device, so exactly one of each must exist.
     */
    public function test_enrollment_records_one_audit_entry_and_one_integrity_report(): void
    {
        [$code, $token] = $this->issueActivationCode();

        $deviceId = $this->enroll($token)->assertCreated()->json('device_id');

        $audits = AuditLog::where('action', 'device.enrolled')->get();

        $this->assertCount(1, $audits);
        $this->assertSame('device', $audits[0]->actor_type);
        $this->assertSame($deviceId, $audits[0]->actor_id);
        $this->assertSame($code->driver_id, $audits[0]->subject_id);

        $this->assertSame(1, IntegrityReport::where('device_id', $deviceId)->count());
    }

    /** PEM for an ES256 key the server has never seen. */
    private function foreignSigningKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not generate a foreign signing key.');
        }

        return $pem;
    }
}
