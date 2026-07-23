<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\IntegrityReport;
use App\Support\DeviceTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Reporting device health on every launch
 * (docs/api/openapi.yaml `/devices/me/integrity`).
 *
 * Every value here is forgeable, which is the point of collecting it centrally:
 * the fleet's own account of itself is worth having even though no single
 * report can be believed. What must hold is that a report can only be filed for
 * the device that signed it, by a session that is still alive.
 */
final class IntegrityReportTest extends ApiTestCase
{
    use RefreshDatabase;

    private string $deviceId;

    private string $accessToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = $this->activeDevice('481923');
        $this->accessToken = DeviceTokens::make()
            ->issue(Device::findOrFail($this->deviceId))['access_token'];
    }

    /** @param array<string, bool> $signals */
    private function report(array $signals, ?string $token = null): TestResponse
    {
        return $this->signedPost(
            '/api/v1/devices/me/integrity',
            $signals,
            headers: ['Authorization' => 'Bearer '.($token ?? $this->accessToken)],
        );
    }

    public function test_a_clean_report_is_accepted_and_stored(): void
    {
        $this->report(['rooted' => false, 'usb_debugging' => false])
            ->assertStatus(202)
            ->assertJsonPath('action', 'allow');

        $report = IntegrityReport::query()->where('device_id', $this->deviceId)->latest('id')->first();

        $this->assertNotNull($report);
        $this->assertSame(0, $report->risk_score);
    }

    public function test_debugging_left_on_is_recorded_as_a_reason(): void
    {
        $this->report(['usb_debugging' => true, 'wireless_debugging' => true])
            ->assertStatus(202);

        $report = IntegrityReport::query()->where('device_id', $this->deviceId)->latest('id')->first();

        $this->assertContains('usb_debugging', $report->verdict['reasons']);
        $this->assertContains('wireless_debugging', $report->verdict['reasons']);
    }

    /**
     * Staff need to see the ones that mattered. A row for every launch of every
     * device would bury them, and this table outlives the data it describes.
     */
    public function test_only_a_flagged_report_is_audited(): void
    {
        $this->report(['rooted' => false]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'device.integrity_flagged',
            'subject_id' => $this->deviceId,
        ]);

        $this->report(['rooted' => true]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.integrity_flagged',
            'subject_id' => $this->deviceId,
        ]);
    }

    /**
     * Self-reported flags may raise the score but never reach a blocking one,
     * however many of them arrive at once (§4.1).
     */
    public function test_every_flag_at_once_still_does_not_block(): void
    {
        config(['security.attestation.enforce' => true]);

        $this->report([
            'rooted' => true,
            'su_binary_found' => true,
            'test_keys' => true,
            'developer_options' => true,
            'usb_debugging' => true,
            'wireless_debugging' => true,
            'emulator' => true,
            'debugger_attached' => true,
            'hook_framework_detected' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('action', fn (string $action) => $action !== 'block');
    }

    /**
     * The token is the only thing naming the device on this path, so without one
     * there is no public key to check the signature against. The answer is about
     * the signature rather than the token, and deliberately says nothing about
     * whether any such device exists.
     */
    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->signedPost('/api/v1/devices/me/integrity', ['rooted' => false])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /**
     * The device is named by the token, so a signature has to come from that
     * same device — otherwise a token lifted from one phone could be spent from
     * another that happens to be enrolled.
     */
    public function test_an_unsigned_request_is_refused(): void
    {
        $this->withHeaders($this->appHeaders(['Authorization' => 'Bearer '.$this->accessToken]))
            ->postJson('/api/v1/devices/me/integrity', ['rooted' => false])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /** A token that does not decode names no device either. */
    public function test_a_garbled_token_is_refused(): void
    {
        $this->report(['rooted' => false], 'not-a-token')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /**
     * A token that decodes but belongs to another device must not let a report
     * be filed against it — the signature has to come from the device the token
     * names.
     */
    public function test_a_token_for_another_device_is_refused(): void
    {
        $other = Device::findOrFail($this->activeDevice('573194'));

        // The harness signs everything with one key, so the second device is
        // given a key of its own. Without this the test would pass whatever the
        // middleware did, because both signatures would verify.
        $other->forceFill(['public_key' => $this->foreignPublicKey()])->save();

        $foreign = DeviceTokens::make()->issue($other)['access_token'];

        $this->report(['rooted' => false], $foreign)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /** An EC P-256 SPKI belonging to nothing, in the form devices.public_key holds. */
    private function foreignPublicKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = (string) (openssl_pkey_get_details($key)['key'] ?? '');

        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /**
     * A token stays cryptographically valid until it expires, so revoking a
     * session has to be checked on every call — otherwise staff revoking a
     * device would not take effect until the token ran out by itself.
     */
    public function test_a_revoked_session_stops_working_immediately(): void
    {
        DeviceSession::query()->where('device_id', $this->deviceId)->update(['revoked_at' => now()]);

        $this->report(['rooted' => false])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_TOKEN_EXPIRED');
    }

    public function test_a_revoked_device_is_refused(): void
    {
        Device::findOrFail($this->deviceId)->update(['status' => 'revoked']);

        $this->report(['rooted' => false])->assertStatus(401);
    }
}
