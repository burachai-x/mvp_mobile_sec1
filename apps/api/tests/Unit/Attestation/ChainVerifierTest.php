<?php

declare(strict_types=1);

namespace Tests\Unit\Attestation;

use App\Support\Attestation\ChainVerifier;
use RuntimeException;
use Tests\Support\FakeAttestation;
use Tests\TestCase;

/**
 * Android Key Attestation chain verification (architecture.md §4.2).
 *
 * Everything here rests on one property: the values are only worth reading once
 * the chain proves a device TEE signed them. Take that away and this layer is no
 * stronger than the app's own self-report, which Frida can rewrite.
 */
final class ChainVerifierTest extends TestCase
{
    private const CHALLENGE = 'challenge-bound-to-this-enrollment';

    public function test_a_well_formed_chain_yields_the_signed_values(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);

        $description = (new ChainVerifier([$root]))->verify($chain);

        $this->assertSame(self::CHALLENGE, $description->attestationChallenge);
        $this->assertSame('Verified', $description->verifiedBootState);
        $this->assertTrue($description->deviceLocked);
        $this->assertSame('StrongBox', $description->attestationSecurityLevel);
    }

    public function test_an_unlocked_bootloader_is_reported(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(
            self::CHALLENGE,
            verifiedBootState: 'Unverified',
            deviceLocked: false,
        );

        $description = (new ChainVerifier([$root]))->verify($chain);

        $this->assertSame('Unverified', $description->verifiedBootState);
        $this->assertFalse($description->deviceLocked);
    }

    /**
     * The case this whole layer exists for: anyone can mint a certificate that
     * claims "Verified", so a chain that does not reach a trusted root must be
     * refused however convincing its contents look.
     */
    public function test_a_chain_anchored_in_an_unknown_root_is_refused(): void
    {
        ['chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);
        ['root' => $unrelatedRoot] = FakeAttestation::chain('other');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/known Google root/');

        (new ChainVerifier([$unrelatedRoot]))->verify($chain);
    }

    /**
     * Splicing an attacker's leaf onto a genuine chain must fail. Checking only
     * the root would accept it, which is why every link is verified.
     */
    public function test_a_spliced_leaf_is_refused(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);
        ['chain' => $foreign] = FakeAttestation::chain('attacker-controlled');

        $spliced = [$foreign[0], $chain[1], $chain[2]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not signed by/');

        (new ChainVerifier([$root]))->verify($spliced);
    }

    public function test_a_certificate_without_the_extension_is_refused(): void
    {
        ['root' => $root, 'chain' => $chain] = FakeAttestation::chain(self::CHALLENGE);

        // The intermediate is an ordinary CA certificate carrying no KeyDescription.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no KeyDescription/');

        (new ChainVerifier([$root]))->verify([$chain[1], $chain[2]]);
    }

    public function test_an_empty_chain_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChainVerifier(['irrelevant']))->verify([]);
    }

    public function test_garbage_is_refused_rather_than_parsed(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChainVerifier(['irrelevant']))->verify(['not a certificate at all']);
    }

    /**
     * The shipped roots are the real trust anchors, so a mistake here disables
     * the layer silently. The original 2016 root expired in May 2026 and was
     * reissued — this catches the next time that happens.
     */
    public function test_the_shipped_google_roots_are_usable_and_current(): void
    {
        $files = glob(resource_path('attestation/*.pem'));

        $this->assertNotEmpty($files, 'No attestation roots are shipped.');

        foreach ($files as $file) {
            $parsed = openssl_x509_parse((string) file_get_contents($file));

            $this->assertIsArray($parsed, "{$file} is not a readable certificate.");
            $this->assertGreaterThan(
                time(),
                $parsed['validTo_time_t'],
                basename($file).' has expired — fetch the current set from '
                .'https://android.googleapis.com/attestation/root'
            );
        }
    }
}
