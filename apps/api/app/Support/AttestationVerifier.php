<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Attestation\ChainVerifier;
use Throwable;

/**
 * Android Key Attestation (architecture.md §4.2).
 *
 * The certificate chain is verified up to a Google root, so the boot state read
 * here was signed by the device's TEE and cannot be produced by an app. That is
 * what separates this from IntegritySignals, which Magisk hides from and Frida
 * rewrites — those are kept only as a weak corroborating signal.
 *
 * Rollout is in monitor mode: the verdict is recorded and alerted on, but does
 * not block, until there is data on what real driver hardware reports. Enforcing
 * on day one would lock out drivers before anyone knows how many that is.
 */
final class AttestationVerifier
{
    public function __construct(private readonly ?ChainVerifier $chain = null) {}

    /**
     * @param  array<string, mixed>  $attestation  as supplied by the app
     * @param  array<string, mixed>  $integrity
     * @return array{risk_score: int, action: string, reasons: list<string>, chain_verified: bool, attested: array<string, mixed>}
     */
    public function assess(array $attestation, array $integrity = [], ?string $expectedChallenge = null): array
    {
        $reasons = [];
        $score = 0;
        $chainVerified = false;
        $attested = [];

        /** @var list<string> $chainPems */
        $chainPems = array_values(array_filter(
            (array) ($attestation['certificate_chain'] ?? []),
            static fn ($pem): bool => is_string($pem) && $pem !== '',
        ));

        if ($chainPems === []) {
            // Older builds, or a caller that simply omitted it. Not proof of
            // anything wrong, but it means nothing here can be trusted.
            $reasons[] = 'no_attestation_chain';
            $score += 30;
        } else {
            try {
                $description = ($this->chain ?? ChainVerifier::make())->verify($chainPems);
                $chainVerified = true;

                $attested = [
                    'verified_boot_state' => $description->verifiedBootState,
                    'device_locked' => $description->deviceLocked,
                    'security_level' => $description->attestationSecurityLevel,
                    'os_patch_level' => $description->osPatchLevel,
                ];

                // Binds the attestation to this enrollment. Without it a chain
                // captured from any genuine device could be replayed here, and
                // every check above it would still pass.
                if ($expectedChallenge !== null
                    && ! hash_equals($expectedChallenge, $description->attestationChallenge)
                ) {
                    $reasons[] = 'challenge_mismatch';
                    $score += 100;
                }

                if ($description->verifiedBootState !== 'Verified') {
                    $reasons[] = 'verified_boot_state='.($description->verifiedBootState ?? 'unknown');
                    $score += 50;
                }

                if ($description->deviceLocked === false) {
                    $reasons[] = 'bootloader_unlocked';
                    $score += 40;
                }

                if ($description->attestationSecurityLevel === 'Software') {
                    $reasons[] = 'key_not_hardware_backed';
                    $score += 40;
                }
            } catch (Throwable $e) {
                // A chain that fails to verify is a stronger signal than no chain
                // at all: something produced one and it did not hold up.
                $reasons[] = 'chain_invalid';
                $attested = ['error' => $e->getMessage()];
                $score += 60;
            }
        }

        // Self-reported and forgeable, so these only nudge the score. They never
        // decide on their own (§4.1).
        foreach (['rooted', 'hook_framework_detected', 'emulator', 'debugger_attached'] as $flag) {
            if (($integrity[$flag] ?? false) === true) {
                $reasons[] = $flag;
                $score += 10;
            }
        }

        return [
            'risk_score' => min($score, 100),
            'action' => $this->decide($score, (bool) config('security.attestation.enforce', false)),
            'reasons' => $reasons,
            'chain_verified' => $chainVerified,
            'attested' => $attested,
        ];
    }

    private function decide(int $score, bool $enforce): string
    {
        if (! $enforce) {
            return $score >= 50 ? 'warn' : 'allow';
        }

        return match (true) {
            $score >= 50 => 'block',
            $score >= 20 => 'warn',
            default => 'allow',
        };
    }
}
