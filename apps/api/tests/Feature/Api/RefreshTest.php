<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\DeviceSession;
use App\Support\DeviceTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Exchanging a refresh token (docs/api/openapi.yaml `/auth/refresh`).
 *
 * This is the path an unlock takes when the driver uses a fingerprint instead
 * of typing the PIN, so it has to be no weaker: signed by the device key,
 * rotating, and fatal to every session if a spent token comes back.
 */
final class RefreshTest extends ApiTestCase
{
    use RefreshDatabase;

    private string $deviceId;

    private string $refreshToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = $this->activeDevice('481923');
        $this->refreshToken = DeviceTokens::make()
            ->issue(Device::findOrFail($this->deviceId))['refresh_token'];
    }

    private function refresh(string $token, ?int $timestamp = null, ?string $nonce = null): TestResponse
    {
        return $this->signedPost(
            '/api/v1/auth/refresh',
            ['device_id' => $this->deviceId, 'refresh_token' => $token],
            $timestamp,
            $nonce,
        );
    }

    public function test_a_valid_refresh_token_returns_a_new_pair(): void
    {
        $response = $this->refresh($this->refreshToken);

        $response->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'expires_in', 'refresh_expires_in',
        ]);

        $this->assertNotSame($this->refreshToken, $response->json('refresh_token'));
    }

    /** Rotation is the point: the old token dies the moment it is spent. */
    public function test_the_old_token_stops_working_immediately(): void
    {
        $this->refresh($this->refreshToken)->assertOk();

        $this->refresh($this->refreshToken)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_TOKEN_EXPIRED');
    }

    /**
     * A spent token coming back means a copy is loose. Refusing only that
     * request would leave whoever holds the newer one still logged in, so every
     * session for the device goes.
     */
    public function test_reusing_a_spent_token_kills_every_session(): void
    {
        $fresh = $this->refresh($this->refreshToken)->json('refresh_token');

        $this->refresh($this->refreshToken)->assertStatus(401);

        $this->assertSame(0, DeviceSession::query()
            ->where('device_id', $this->deviceId)
            ->whereNull('revoked_at')
            ->count());

        // Including the one handed out a moment ago, which is the whole point.
        $this->refresh($fresh)->assertStatus(401);
    }

    public function test_reuse_is_audited_so_staff_can_see_it(): void
    {
        $this->refresh($this->refreshToken);
        $this->refresh($this->refreshToken);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.refresh_rejected',
            'subject_id' => $this->deviceId,
        ]);
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->refresh('not-a-real-token')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_TOKEN_EXPIRED');
    }

    /**
     * Without a signature, anyone holding a stolen refresh token could mint
     * fresh sessions from any device at all.
     */
    public function test_an_unsigned_request_is_refused(): void
    {
        $this->withHeaders($this->appHeaders())
            ->postJson('/api/v1/auth/refresh', [
                'device_id' => $this->deviceId,
                'refresh_token' => $this->refreshToken,
            ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /** A revoked device must not unlock itself back into service. */
    public function test_a_revoked_device_is_refused(): void
    {
        Device::findOrFail($this->deviceId)->update(['status' => 'revoked']);

        $this->refresh($this->refreshToken)->assertStatus(401);
    }

    /** The replay guard has to catch this, not the token rotation. */
    public function test_a_replayed_request_is_refused(): void
    {
        $timestamp = time();
        $nonce = 'a-fixed-nonce-for-this-test';

        $this->refresh($this->refreshToken, $timestamp, $nonce)->assertOk();

        $this->refresh($this->refreshToken, $timestamp, $nonce)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }
}
