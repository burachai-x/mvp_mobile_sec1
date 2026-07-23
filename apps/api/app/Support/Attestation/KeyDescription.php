<?php

declare(strict_types=1);

namespace App\Support\Attestation;

use phpseclib3\File\ASN1;
use RuntimeException;

/**
 * The KeyDescription extension inside an Android Key Attestation leaf
 * certificate (OID 1.3.6.1.4.1.11129.2.1.17).
 *
 * This is where the values that actually matter live — the boot state and the
 * challenge — and they are signed by the device's TEE, which is why they cannot
 * be forged by an app the way IntegritySignals can (architecture.md §4.2).
 *
 * Schema (abridged):
 *
 *   KeyDescription ::= SEQUENCE {
 *       attestationVersion       INTEGER,
 *       attestationSecurityLevel ENUMERATED,
 *       keymasterVersion         INTEGER,
 *       keymasterSecurityLevel   ENUMERATED,
 *       attestationChallenge     OCTET STRING,
 *       uniqueId                 OCTET STRING,
 *       softwareEnforced         AuthorizationList,
 *       teeEnforced              AuthorizationList,
 *   }
 *
 *   AuthorizationList ::= SEQUENCE {  -- sparse, context-tagged
 *       rootOfTrust  [704] EXPLICIT RootOfTrust OPTIONAL,
 *       osVersion    [705] EXPLICIT INTEGER     OPTIONAL,
 *       osPatchLevel [706] EXPLICIT INTEGER     OPTIONAL,
 *   }
 *
 *   RootOfTrust ::= SEQUENCE {
 *       verifiedBootKey   OCTET STRING,
 *       deviceLocked      BOOLEAN,
 *       verifiedBootState ENUMERATED,
 *   }
 */
final class KeyDescription
{
    public const OID = '1.3.6.1.4.1.11129.2.1.17';

    private const SECURITY_LEVELS = [0 => 'Software', 1 => 'TrustedEnvironment', 2 => 'StrongBox'];

    private const BOOT_STATES = [0 => 'Verified', 1 => 'SelfSigned', 2 => 'Unverified', 3 => 'Failed'];

    private const TAG_ROOT_OF_TRUST = 704;

    private const TAG_OS_VERSION = 705;

    private const TAG_OS_PATCH_LEVEL = 706;

    private function __construct(
        public readonly int $attestationVersion,
        public readonly string $attestationSecurityLevel,
        public readonly string $keymasterSecurityLevel,
        public readonly string $attestationChallenge,
        public readonly ?bool $deviceLocked,
        public readonly ?string $verifiedBootState,
        public readonly ?int $osVersion,
        public readonly ?int $osPatchLevel,
    ) {}

    /** @param string $der raw DER of the extension value */
    public static function fromDer(string $der): self
    {
        $decoded = ASN1::decodeBER($der);

        if ($decoded === false || ! isset($decoded[0]['content'])) {
            throw new RuntimeException('KeyDescription is not valid DER.');
        }

        $top = $decoded[0]['content'];

        if (count($top) < 8) {
            throw new RuntimeException('KeyDescription has fewer fields than the schema requires.');
        }

        // teeEnforced is what the hardware vouches for. softwareEnforced (index 6)
        // is filled in by the OS and carries no more weight than the app's own
        // claims, so the boot state is read from teeEnforced only.
        $teeEnforced = self::authorizationList($top[7]['content'] ?? []);
        $rootOfTrust = $teeEnforced[self::TAG_ROOT_OF_TRUST] ?? null;

        return new self(
            attestationVersion: (int) (string) ($top[0]['content'] ?? 0),
            attestationSecurityLevel: self::SECURITY_LEVELS[self::enumValue($top[1])] ?? 'Unknown',
            keymasterSecurityLevel: self::SECURITY_LEVELS[self::enumValue($top[3])] ?? 'Unknown',
            attestationChallenge: (string) ($top[4]['content'] ?? ''),
            deviceLocked: $rootOfTrust['deviceLocked'] ?? null,
            verifiedBootState: $rootOfTrust['verifiedBootState'] ?? null,
            osVersion: isset($teeEnforced[self::TAG_OS_VERSION])
                ? (int) $teeEnforced[self::TAG_OS_VERSION] : null,
            osPatchLevel: isset($teeEnforced[self::TAG_OS_PATCH_LEVEL])
                ? (int) $teeEnforced[self::TAG_OS_PATCH_LEVEL] : null,
        );
    }

    /**
     * AuthorizationList is a sparse sequence of context-tagged, explicitly
     * wrapped values, so it is keyed by tag number rather than position.
     *
     * @param  array<int, mixed>  $entries
     * @return array<int, mixed>
     */
    private static function authorizationList(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['constant'])) {
                continue;
            }

            $tag = (int) $entry['constant'];
            $inner = $entry['content'][0] ?? null;

            $out[$tag] = match ($tag) {
                self::TAG_ROOT_OF_TRUST => self::rootOfTrust($inner),
                self::TAG_OS_VERSION, self::TAG_OS_PATCH_LEVEL => (string) ($inner['content'] ?? ''),
                default => null,
            };
        }

        return $out;
    }

    /** @return array{deviceLocked: ?bool, verifiedBootState: ?string} */
    private static function rootOfTrust(mixed $node): array
    {
        $fields = is_array($node) ? ($node['content'] ?? []) : [];

        return [
            // Index 0 is verifiedBootKey, which we do not pin: it varies per
            // manufacturer and pinning it would reject legitimate devices.
            'deviceLocked' => isset($fields[1]) ? (bool) $fields[1]['content'] : null,
            'verifiedBootState' => isset($fields[2])
                ? (self::BOOT_STATES[self::enumValue($fields[2])] ?? 'Unknown')
                : null,
        ];
    }

    private static function enumValue(mixed $node): int
    {
        if (! is_array($node)) {
            return -1;
        }

        $content = $node['content'] ?? null;

        // phpseclib hands back a BigInteger for INTEGER/ENUMERATED.
        return is_object($content) ? (int) (string) $content : (int) $content;
    }
}
