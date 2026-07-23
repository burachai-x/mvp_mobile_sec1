<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Device;
use Illuminate\Testing\TestResponse;

/**
 * PIN setup and unlock (architecture.md §6.4, openapi.yaml /devices/{id}/pin
 * and /auth/pin/verify).
 *
 * A six-digit PIN is 10^6 possibilities, so nothing here is protected by the
 * secret itself. What protects it is the hardware-backed request signature on
 * every call plus server-side lockout — these tests exist to notice if either
 * stops holding.
 */
final class PinTest extends ApiTestCase
{
    private const GOOD_PIN = '481902';

    public function test_setting_the_first_pin_activates_the_device(): void
    {
        [$deviceId, $setupToken] = $this->enrollDevice();

        $response = $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => self::GOOD_PIN,
        ]);

        $response->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'expires_in', 'refresh_expires_in',
        ]);

        $device = Device::findOrFail($deviceId);

        $this->assertSame('active', $device->status);
        $this->assertSame(1, $device->sessions()->count());
    }

    /**
     * The token is consumed on first use, so a captured enrollment response
     * cannot be replayed later to overwrite someone's PIN.
     *
     * The device is put back to `pending_pin` by hand — the way a staff reset
     * would — but without issuing a fresh token, so the rejection can only come
     * from the token itself and not from the status guard.
     */
    public function test_a_spent_pin_setup_token_is_rejected(): void
    {
        [$deviceId, $setupToken] = $this->enrollDevice();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => self::GOOD_PIN,
        ])->assertStatus(200);

        Device::findOrFail($deviceId)->forceFill(['status' => 'pending_pin'])->save();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => self::GOOD_PIN,
        ])->assertStatus(401)->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');

        $this->assertSame('pending_pin', Device::findOrFail($deviceId)->status);
    }

    /**
     * A rejected PIN must not cost the driver their token. Burning it on a typo
     * would send them back to staff for a §6.7 reset just for typing 123456.
     */
    public function test_a_rejected_weak_pin_leaves_the_setup_token_usable(): void
    {
        [$deviceId, $setupToken] = $this->enrollDevice();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => '111111',
        ])->assertStatus(422);

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => self::GOOD_PIN,
        ])->assertStatus(200);

        $this->assertSame('active', Device::findOrFail($deviceId)->status);
    }

    /**
     * Replaying the token after setup succeeded must not silently rewrite the
     * PIN of a device that is already in the driver's hands.
     */
    public function test_a_pin_setup_token_cannot_be_used_twice(): void
    {
        [$deviceId, $setupToken] = $this->enrollDevice();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => self::GOOD_PIN,
        ])->assertOk();

        $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
            'pin_setup_token' => $setupToken,
            'pin' => '739154',
        ])->assertStatus(401)->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');

        // The original PIN must survive the attempt, otherwise the driver is
        // locked out of a device that still reports as active.
        $this->signedPost('/api/v1/auth/pin/verify', [
            'device_id' => $deviceId,
            'pin' => self::GOOD_PIN,
        ])->assertOk();
    }

    /**
     * These are the first values a thief with the phone would try, so accepting
     * them would waste the lockout budget that is the real defence.
     */
    public function test_obvious_pins_are_rejected(): void
    {
        foreach (['111111', '123456', '000000'] as $index => $weakPin) {
            // A rejected PIN burns its single-use setup token, so each attempt
            // needs its own enrollment. Those are three different phones, and the
            // throttle bucket is keyed on client IP, so they must not share one.
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($index + 1)]);

            [$deviceId, $setupToken] = $this->enrollDevice();

            $this->signedPost("/api/v1/devices/{$deviceId}/pin", [
                'pin_setup_token' => $setupToken,
                'pin' => $weakPin,
            ])->assertStatus(422)->assertJsonPath('error.code', 'E_VALIDATION_FAILED');

            $this->assertSame('pending_pin', Device::findOrFail($deviceId)->status);
        }
    }

    /**
     * Without this a captured request is a reusable credential: the signature
     * stays valid for as long as the timestamp is inside the skew window.
     */
    public function test_a_replayed_nonce_is_rejected(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        $timestamp = time();
        $nonce = 'fixed-nonce-for-replay';
        $payload = ['device_id' => $deviceId, 'pin' => self::GOOD_PIN];

        $this->signedPost('/api/v1/auth/pin/verify', $payload, $timestamp, $nonce)->assertOk();

        $this->signedPost('/api/v1/auth/pin/verify', $payload, $timestamp, $nonce)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /**
     * The window is bounded on both sides. A future-dated timestamp would let an
     * attacker mint a request that is still replayable after the nonce record
     * has expired.
     */
    public function test_a_timestamp_outside_the_skew_window_is_rejected(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);
        $payload = ['device_id' => $deviceId, 'pin' => self::GOOD_PIN];

        $this->signedPost('/api/v1/auth/pin/verify', $payload, time() - 120)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');

        $this->signedPost('/api/v1/auth/pin/verify', $payload, time() + 120)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /**
     * The body hash is part of the canonical string, so a signature taken from
     * one request must not authenticate a different payload — otherwise an
     * intercepted signature could be pasted onto an attacker's own PIN.
     */
    public function test_a_signature_over_a_different_body_is_rejected(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        $this->signedPost(
            '/api/v1/auth/pin/verify',
            ['device_id' => $deviceId, 'pin' => self::GOOD_PIN],
            sentBody: json_encode(['device_id' => $deviceId, 'pin' => '000001'], JSON_THROW_ON_ERROR),
        )->assertStatus(401)->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');

        // Rejected before the controller ran, so the swapped PIN must not even
        // count as a failed attempt.
        $this->assertSame(0, Device::findOrFail($deviceId)->pin_failed_count);
    }

    public function test_five_wrong_pins_lock_the_device(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->wrongPin($deviceId)->assertStatus(401);
        }

        $this->wrongPin($deviceId)
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'E_PIN_LOCKED')
            ->assertJsonPath('error.retry_after', 15 * 60);

        // The lockout has to survive a correct PIN as well; otherwise guessing
        // costs nothing more than one extra round trip.
        $this->signedPost('/api/v1/auth/pin/verify', ['device_id' => $deviceId, 'pin' => self::GOOD_PIN])
            ->assertStatus(423);
    }

    /**
     * A wrong PIN says so, and says how many tries are left.
     *
     * These two answers used to be identical, so that someone with a stolen
     * phone could not tell which half they had failed. That hid nothing:
     * reaching this endpoint requires a signature from a key inside the device
     * TEE, so the only party who can see the difference is the enrolled device,
     * which already knows its own signature is good. Guessing is bounded by the
     * lockout below, not by the shape of this response.
     *
     * The cost was real — §7 has the app clear its session and re-enroll on
     * E_DEVICE_SIGNATURE_INVALID, so one mistyped digit sent a driver back to
     * staff for a new activation code.
     */
    public function test_a_wrong_pin_is_reported_as_a_wrong_pin(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        $this->wrongPin($deviceId)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_PIN_INVALID')
            ->assertJsonPath('error.attempts_remaining', 4);

        $this->signedPost(
            '/api/v1/auth/pin/verify',
            ['device_id' => $deviceId, 'pin' => self::GOOD_PIN],
            headers: ['X-Device-Signature' => base64_encode('not-a-signature')],
        )
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'E_DEVICE_SIGNATURE_INVALID');
    }

    /** The count has to be useful, or it is just noise next to the error. */
    public function test_attempts_remaining_counts_down_to_the_lockout(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        foreach ([4, 3, 2, 1] as $remaining) {
            $this->wrongPin($deviceId)->assertJsonPath('error.attempts_remaining', $remaining);
        }

        $this->wrongPin($deviceId)->assertJsonPath('error.code', 'E_PIN_LOCKED');
    }

    /**
     * Ten failures in total means someone is working through the keyspace rather
     * than fumbling their own PIN, so the device stops being usable until staff
     * intervene (§6.4). A repeating 15-minute lockout alone would still leave
     * roughly 480 guesses a day available forever.
     */
    public function test_sustained_wrong_pins_block_the_device(): void
    {
        $deviceId = $this->activeDevice(self::GOOD_PIN);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->wrongPin($deviceId);
        }

        // Waiting out the 15-minute lockout is free for an attacker; the total
        // counter is what must not reset when they come back.
        $this->travel(61)->minutes();

        for ($attempt = 6; $attempt <= 9; $attempt++) {
            $this->wrongPin($deviceId)->assertStatus(401);
        }

        $this->wrongPin($deviceId)
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'E_PIN_LOCKED');

        $device = Device::findOrFail($deviceId);

        $this->assertSame('blocked', $device->status);
        $this->assertSame(10, $device->pin_failed_count);
    }

    private function wrongPin(string $deviceId): TestResponse
    {
        return $this->signedPost('/api/v1/auth/pin/verify', [
            'device_id' => $deviceId,
            'pin' => '739154',
        ]);
    }
}
