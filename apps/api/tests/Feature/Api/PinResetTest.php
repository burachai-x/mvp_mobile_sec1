<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Finishing a staff PIN reset (PRD §6.7).
 *
 * The reset itself already worked: it cleared the hash and moved the device to
 * `pending_pin`. What was missing was any way for the driver to set the new PIN
 * — setup tokens were only ever issued at enrollment — so a reset left the
 * device permanently unusable and staff had to delete and re-enroll it.
 */
final class PinResetTest extends ApiTestCase
{
    use RefreshDatabase;

    private const PIN = '481923';

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = $this->activeDevice(self::PIN);
    }

    /** What the staff action does, without going through Filament. */
    private function staffReset(): void
    {
        DB::table('devices')->where('id', $this->deviceId)->update([
            'status' => 'pending_pin',
            'pin_hash' => null,
            'pin_failed_count' => 0,
            'pin_locked_until' => null,
        ]);
    }

    private function requestToken(): TestResponse
    {
        return $this->signedPost("/api/v1/devices/{$this->deviceId}/pin/setup-token", []);
    }

    /**
     * The whole point: a driver whose PIN was reset can set a new one and get
     * back to work on the same device.
     */
    public function test_a_driver_can_set_a_new_pin_after_a_reset(): void
    {
        $this->staffReset();

        $token = $this->requestToken()->assertOk()->json('pin_setup_token');

        $this->signedPost("/api/v1/devices/{$this->deviceId}/pin", [
            'pin_setup_token' => $token,
            'pin' => '573194',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);

        $this->assertSame('active', Device::findOrFail($this->deviceId)->status);

        $this->signedPost('/api/v1/auth/pin/verify', [
            'device_id' => $this->deviceId,
            'pin' => '573194',
        ])->assertOk();
    }

    /**
     * The old PIN must not survive a reset, or the reset achieved nothing for a
     * driver who has forgotten it and someone else knows it.
     */
    public function test_the_old_pin_no_longer_works(): void
    {
        $this->staffReset();

        $this->signedPost('/api/v1/auth/pin/verify', [
            'device_id' => $this->deviceId,
            'pin' => self::PIN,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'E_PIN_RESET_REQUIRED');
    }

    /**
     * The app has to be able to tell "set a new PIN" from "this device is
     * broken". Answering the same for both is what sent drivers to re-enroll.
     */
    public function test_unlocking_says_a_reset_is_pending(): void
    {
        $this->staffReset();

        $this->signedPost('/api/v1/auth/pin/verify', [
            'device_id' => $this->deviceId,
            'pin' => '000000',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'E_PIN_RESET_REQUIRED');
    }

    /**
     * A working device must not be able to talk itself into a new PIN: that
     * would let anyone holding an unlocked phone replace the PIN without
     * knowing the old one.
     */
    public function test_an_active_device_is_refused_a_setup_token(): void
    {
        $this->requestToken()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'E_PIN_ALREADY_SET');
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $this->staffReset();

        $this->withHeaders($this->appHeaders())
            ->postJson("/api/v1/devices/{$this->deviceId}/pin/setup-token", [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /** Single use, so a token seen once cannot be spent twice. */
    public function test_a_setup_token_works_only_once(): void
    {
        $this->staffReset();

        $token = $this->requestToken()->json('pin_setup_token');

        $this->signedPost("/api/v1/devices/{$this->deviceId}/pin", [
            'pin_setup_token' => $token,
            'pin' => '573194',
        ])->assertOk();

        $this->staffReset();

        $this->signedPost("/api/v1/devices/{$this->deviceId}/pin", [
            'pin_setup_token' => $token,
            'pin' => '246813',
        ])->assertStatus(401);
    }

    /**
     * Asking again replaces the previous token rather than adding one — and a
     * rejected attempt must not destroy the token that would have worked.
     * Deleting before comparing meant one wrong guess locked the driver out
     * until they asked for another.
     */
    public function test_a_new_request_retires_the_previous_token(): void
    {
        $this->staffReset();

        $first = $this->requestToken()->json('pin_setup_token');
        $second = $this->requestToken()->json('pin_setup_token');

        $this->assertNotSame($first, $second);

        $this->signedPost("/api/v1/devices/{$this->deviceId}/pin", [
            'pin_setup_token' => $first,
            'pin' => '573194',
        ])->assertStatus(401);

        $this->signedPost("/api/v1/devices/{$this->deviceId}/pin", [
            'pin_setup_token' => $second,
            'pin' => '573194',
        ])->assertOk();
    }

    public function test_issuing_a_token_is_audited(): void
    {
        $this->staffReset();
        $this->requestToken()->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pin.setup_token_issued',
            'subject_id' => $this->deviceId,
        ]);
    }

    public function test_a_revoked_device_gets_nothing(): void
    {
        DB::table('devices')->where('id', $this->deviceId)->update(['status' => 'revoked']);

        $this->requestToken()->assertStatus(401);
    }
}
