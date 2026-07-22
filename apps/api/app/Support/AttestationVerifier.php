<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Android Key Attestation (architecture.md §4.2).
 *
 * ⚠️ SCOPE — read before relying on this.
 *
 * This does NOT yet verify the attestation certificate chain. It records what
 * the app reported and scores it, which is useful for the monitor-mode rollout
 * but is *not* the security control described in §4.2.
 *
 * Until `verifyChain()` is implemented, these values carry exactly as much
 * weight as IntegritySignals: the app supplied them and a rooted device can
 * supply anything. The real control needs the chain parsed and validated up to
 * Google's attestation root, with the KeyDescription extension read from the
 * leaf — that is where verifiedBootState actually lives and where it cannot be
 * forged.
 *
 * Enforcement stays off until then. Turning KEY_ATTESTATION_ENFORCE on now
 * would only block devices honest enough to report their own root status.
 */
final class AttestationVerifier
{
    /**
     * @param  array<string, mixed>  $attestation  as reported by the app
     * @return array{risk_score: int, action: string, reasons: list<string>, chain_verified: bool}
     */
    public function assess(array $attestation, array $integrity = []): array
    {
        $reasons = [];
        $score = 0;

        $bootState = $attestation['verified_boot_state'] ?? null;
        if ($bootState !== null && $bootState !== 'Verified') {
            $reasons[] = "verified_boot_state={$bootState}";
            $score += 50;
        }

        if (($attestation['device_locked'] ?? null) === false) {
            $reasons[] = 'bootloader_unlocked';
            $score += 40;
        }

        if (($attestation['security_level'] ?? null) === 'Software') {
            $reasons[] = 'key_not_hardware_backed';
            $score += 40;
        }

        // Client-side checks. Forgeable, so they only nudge the score — never
        // decide on their own (§4.1).
        foreach (['rooted', 'hook_framework_detected', 'emulator', 'debugger_attached'] as $flag) {
            if (($integrity[$flag] ?? false) === true) {
                $reasons[] = $flag;
                $score += 10;
            }
        }

        $enforce = (bool) config('security.attestation.enforce', false);

        return [
            'risk_score' => min($score, 100),
            'action' => $this->decide($score, $enforce),
            'reasons' => $reasons,
            // Explicit so nothing downstream mistakes a recorded claim for a
            // verified one.
            'chain_verified' => false,
        ];
    }

    private function decide(int $score, bool $enforce): string
    {
        if (! $enforce) {
            // Monitor mode: still surface it, but let the device enroll so we
            // learn what real driver hardware looks like before blocking anyone.
            return $score >= 50 ? 'warn' : 'allow';
        }

        return match (true) {
            $score >= 50 => 'block',
            $score >= 20 => 'warn',
            default => 'allow',
        };
    }
}
