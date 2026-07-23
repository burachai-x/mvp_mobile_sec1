<?php

declare(strict_types=1);

namespace Tests\Unit\Attestation;

use App\Support\Attestation\ChainVerifier;
use App\Support\AttestationVerifier;
use Tests\Support\FakeAttestation;
use Tests\TestCase;

/**
 * Scoring of an attestation result (architecture.md §4.2).
 *
 * Two things are being asserted throughout: that a verified chain is treated as
 * evidence while the app's self-report is not, and that monitor mode records
 * everything without locking anyone out.
 */
final class AttestationVerifierTest extends TestCase
{
    private const CHALLENGE = 'enrollment-specific-challenge';

    private function verifier(string $root): AttestationVerifier
    {
        return new AttestationVerifier(new ChainVerifier([$root]));
    }

    public function test_a_healthy_device_scores_clean(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain], [], self::CHALLENGE
        );

        $this->assertTrue($result['chain_verified']);
        $this->assertSame(0, $result['risk_score']);
        $this->assertSame('allow', $result['action']);
        $this->assertSame('Verified', $result['attested']['verified_boot_state']);
    }

    /**
     * The attack a chain check alone does not stop: capture a genuine device's
     * attestation and present it as your own. Only the challenge binding catches
     * it, and every other check passes while it happens.
     */
    public function test_a_replayed_attestation_from_another_enrollment_is_caught(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain('someone-elses-enrollment');

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain], [], self::CHALLENGE
        );

        // The chain itself is perfectly valid — that is the point.
        $this->assertTrue($result['chain_verified']);
        $this->assertContains('challenge_mismatch', $result['reasons']);
        $this->assertSame(100, $result['risk_score']);
    }

    public function test_an_unlocked_bootloader_is_scored_high(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(
            self::CHALLENGE, verifiedBootState: 'Unverified', deviceLocked: false,
        );

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain], [], self::CHALLENGE
        );

        $this->assertContains('bootloader_unlocked', $result['reasons']);
        $this->assertGreaterThanOrEqual(50, $result['risk_score']);
    }

    /**
     * A chain that exists but does not verify means something produced one and
     * it did not hold up — a stronger signal than a device that sent none.
     */
    public function test_an_invalid_chain_scores_worse_than_a_missing_one(): void
    {
        ['chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);
        ['root' => $unrelated] = FakeAttestation::chain('other');

        $invalid = $this->verifier($unrelated)->assess(['certificate_chain' => $chain], [], self::CHALLENGE);
        $missing = $this->verifier($unrelated)->assess([], [], self::CHALLENGE);

        $this->assertFalse($invalid['chain_verified']);
        $this->assertContains('chain_invalid', $invalid['reasons']);
        $this->assertContains('no_attestation_chain', $missing['reasons']);
        $this->assertGreaterThan($missing['risk_score'], $invalid['risk_score']);
    }

    /**
     * Self-reported flags are forgeable, so on their own they must never reach a
     * blocking score. Otherwise an honest device that admits it is rooted gets
     * punished while a hidden one sails through.
     */
    public function test_self_reported_flags_alone_never_reach_blocking(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);
        config(['security.attestation.enforce' => true]);

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain],
            // Every one of them at once, including the debugging settings. The
            // ceiling has to hold no matter how many flags exist, because the
            // list grows and each addition looks harmless on its own.
            [
                'rooted' => true,
                'hook_framework_detected' => true,
                'emulator' => true,
                'debugger_attached' => true,
                'usb_debugging' => true,
                'wireless_debugging' => true,
                'developer_options' => true,
            ],
            self::CHALLENGE,
        );

        $this->assertNotSame('block', $result['action']);
        $this->assertLessThan(50, $result['risk_score']);
    }

    /** Debugging left on is recorded, and is the kind a driver can act on. */
    public function test_debugging_settings_are_reported_as_reasons(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain],
            ['usb_debugging' => true, 'wireless_debugging' => true],
            self::CHALLENGE,
        );

        $this->assertContains('usb_debugging', $result['reasons']);
        $this->assertContains('wireless_debugging', $result['reasons']);
        $this->assertSame('allow', $result['action']);
    }

    /** Monitor mode records the problem and lets the device through (§4.2). */
    public function test_monitor_mode_warns_instead_of_blocking(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(
            self::CHALLENGE, verifiedBootState: 'Failed', deviceLocked: false,
        );
        config(['security.attestation.enforce' => false]);

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain], [], self::CHALLENGE
        );

        $this->assertSame('warn', $result['action']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_enforcing_blocks_the_same_device(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(
            self::CHALLENGE, verifiedBootState: 'Failed', deviceLocked: false,
        );
        config(['security.attestation.enforce' => true]);

        $result = $this->verifier($root)->assess(
            ['certificate_chain' => $chain], [], self::CHALLENGE
        );

        $this->assertSame('block', $result['action']);
    }

    /**
     * Only values the chain proved may be stored as `attested`. Keeping the raw
     * payload under that name would leave unverified claims looking like
     * evidence to whoever reads the report back.
     */
    public function test_only_verified_values_are_reported_as_attested(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);

        $result = $this->verifier($root)->assess([
            'certificate_chain' => $chain,
            'verified_boot_state' => 'Verified',
            'device_locked' => true,
        ], [], self::CHALLENGE);

        $this->assertArrayNotHasKey('certificate_chain', $result['attested']);
        $this->assertEqualsCanonicalizing(
            ['verified_boot_state', 'device_locked', 'security_level', 'os_patch_level'],
            array_keys($result['attested']),
        );
    }
}
