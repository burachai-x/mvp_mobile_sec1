<?php

declare(strict_types=1);

namespace Tests\Support;

use phpseclib3\File\ASN1;

/**
 * Builds an attestation chain with a locally generated root.
 *
 * Real chains are signed by Google and cannot be produced here, so the tests
 * point ChainVerifier at this root instead. That exercises the parsing, the
 * link-by-link signature checks and the anchor comparison — everything except
 * whether Google's actual roots are the right bytes, which only a real device
 * can confirm.
 */
final class FakeAttestation
{
    /** @return array{root: string, chain: list<string>} */
    public static function chain(
        string $challenge,
        string $verifiedBootState = 'Verified',
        bool $deviceLocked = true,
        string $securityLevel = 'StrongBox',
        ?string $signWithForeignRoot = null,
    ): array {
        [$rootKey, $rootPem] = self::selfSigned('Test Attestation Root');
        [$interKey, $interPem] = self::issued('Test Intermediate', $rootKey, $rootPem);

        $extension = self::keyDescription($challenge, $verifiedBootState, $deviceLocked, $securityLevel);
        [, $leafPem] = self::issued('Test Leaf', $interKey, $interPem, $extension);

        return [
            'root' => $signWithForeignRoot ?? $rootPem,
            'chain' => [$leafPem, $interPem, $rootPem],
        ];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function selfSigned(string $cn): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return [$key, $pem];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function issued(
        string $cn,
        \OpenSSLAsymmetricKey $issuerKey,
        string $issuerPem,
        ?string $extensionDer = null,
    ): array {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);

        $config = ['digest_alg' => 'sha256'];

        if ($extensionDer !== null) {
            // openssl_csr_sign cannot add an arbitrary OID, so the extension is
            // spliced into the DER after signing and the certificate re-signed.
            $config['config'] = self::opensslConfigWith($extensionDer);
            $config['x509_extensions'] = 'attestation';
        }

        $cert = openssl_csr_sign($csr, $issuerPem, $issuerKey, 3650, $config);
        openssl_x509_export($cert, $pem);

        return [$key, $pem];
    }

    /** Writes a throwaway openssl.cnf carrying the KeyDescription as a raw DER blob. */
    private static function opensslConfigWith(string $der): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attest').'.cnf';

        file_put_contents($path, implode("\n", [
            '[ req ]',
            'distinguished_name = dn',
            '[ dn ]',
            '[ attestation ]',
            '1.3.6.1.4.1.11129.2.1.17 = DER:'.strtoupper(bin2hex($der)),
            '',
        ]));

        return $path;
    }

    private static function keyDescription(
        string $challenge,
        string $verifiedBootState,
        bool $deviceLocked,
        string $securityLevel,
    ): string {
        $levels = ['Software' => 0, 'TrustedEnvironment' => 1, 'StrongBox' => 2];
        $states = ['Verified' => 0, 'SelfSigned' => 1, 'Unverified' => 2, 'Failed' => 3];

        $rootOfTrust = self::seq(
            self::octet(random_bytes(32))
            .self::boolean($deviceLocked)
            .self::enum($states[$verifiedBootState] ?? 2)
        );

        $teeEnforced = self::seq(
            self::explicitTag(704, $rootOfTrust)
            .self::explicitTag(705, self::integer(140000))
            .self::explicitTag(706, self::integer(202606))
        );

        return self::seq(
            self::integer(300)                                  // attestationVersion
            .self::enum($levels[$securityLevel] ?? 1)           // attestationSecurityLevel
            .self::integer(300)                                 // keymasterVersion
            .self::enum($levels[$securityLevel] ?? 1)           // keymasterSecurityLevel
            .self::octet($challenge)                            // attestationChallenge
            .self::octet('')                                    // uniqueId
            .self::seq('')                                      // softwareEnforced
            .$teeEnforced
        );
    }

    private static function tlv(int $tag, string $value): string
    {
        return chr($tag).ASN1::encodeLength(strlen($value)).$value;
    }

    private static function seq(string $v): string
    {
        return self::tlv(0x30, $v);
    }

    private static function octet(string $v): string
    {
        return self::tlv(0x04, $v);
    }

    private static function boolean(bool $v): string
    {
        return self::tlv(0x01, $v ? "\xFF" : "\x00");
    }

    private static function enum(int $v): string
    {
        return self::tlv(0x0A, chr($v));
    }

    private static function integer(int $v): string
    {
        $bytes = ltrim(pack('N', $v), "\x00") ?: "\x00";

        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return self::tlv(0x02, $bytes);
    }

    private static function explicitTag(int $tag, string $value): string
    {
        // Context-specific, constructed, high tag number (>30) needs multi-byte form.
        $encoded = '';
        $t = $tag;
        $stack = [$t & 0x7F];
        $t >>= 7;

        while ($t > 0) {
            array_unshift($stack, ($t & 0x7F) | 0x80);
            $t >>= 7;
        }

        $encoded = chr(0xA0 | 0x1F).implode('', array_map('chr', $stack));

        return $encoded.ASN1::encodeLength(strlen($value)).$value;
    }
}
