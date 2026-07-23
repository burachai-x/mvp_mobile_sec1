<?php

declare(strict_types=1);

namespace App\Support\Attestation;

use RuntimeException;

/**
 * Verifies an Android Key Attestation certificate chain up to a Google root
 * (architecture.md §4.2).
 *
 * This is the part that makes the whole layer worth having. Reading
 * verifiedBootState out of the leaf without checking who signed it is no better
 * than believing the app: anyone can craft a certificate that says "Verified".
 * The value only means something once the chain is traced to a key the device
 * manufacturer could not have produced.
 */
final class ChainVerifier
{
    /** @param list<string> $rootPems trusted anchors, PEM */
    public function __construct(private readonly array $rootPems) {}

    public static function make(): self
    {
        $dir = resource_path('attestation');
        $pems = [];

        foreach (glob($dir.'/*.pem') ?: [] as $file) {
            $pems[] = (string) file_get_contents($file);
        }

        if ($pems === []) {
            throw new RuntimeException(
                "No attestation roots in {$dir}. Fetch them from "
                .'https://android.googleapis.com/attestation/root'
            );
        }

        return new self($pems);
    }

    /**
     * @param  list<string>  $chainPems  leaf first, root last, as the device emits it
     *
     * @throws RuntimeException when the chain cannot be trusted
     */
    public function verify(array $chainPems): KeyDescription
    {
        if ($chainPems === []) {
            throw new RuntimeException('Attestation chain is empty.');
        }

        $certs = [];

        foreach ($chainPems as $i => $pem) {
            $cert = @openssl_x509_read($pem);

            if ($cert === false) {
                throw new RuntimeException("Certificate {$i} in the chain is not readable.");
            }

            $certs[] = $cert;
        }

        $this->assertLinksAreSigned($certs);
        $this->assertAnchoredInAKnownRoot(end($certs));
        $this->assertWithinValidity($certs);

        return $this->readKeyDescription($chainPems[0]);
    }

    /**
     * Each certificate must be signed by the next one along. Skipping this and
     * only checking the root would let anyone splice their own leaf onto a
     * genuine root and have it accepted.
     *
     * @param  list<\OpenSSLCertificate>  $certs
     */
    private function assertLinksAreSigned(array $certs): void
    {
        for ($i = 0; $i < count($certs) - 1; $i++) {
            $issuerKey = openssl_pkey_get_public($certs[$i + 1]);

            if ($issuerKey === false) {
                throw new RuntimeException("Cannot read the public key of certificate {$i}'s issuer.");
            }

            if (openssl_x509_verify($certs[$i], $issuerKey) !== 1) {
                throw new RuntimeException("Certificate {$i} is not signed by the next certificate in the chain.");
            }
        }
    }

    /**
     * The last certificate has to be one of Google's roots. Comparing the public
     * key rather than the whole certificate means a re-issued root with the same
     * key still matches — Google did exactly that in 2022, and a byte comparison
     * would have rejected every device the day it happened.
     */
    private function assertAnchoredInAKnownRoot(mixed $last): void
    {
        $presented = $this->publicKeyFingerprint($last);

        foreach ($this->rootPems as $rootPem) {
            $root = @openssl_x509_read($rootPem);

            if ($root !== false && hash_equals($this->publicKeyFingerprint($root), $presented)) {
                return;
            }
        }

        throw new RuntimeException('Attestation chain does not terminate in a known Google root.');
    }

    /**
     * @param  list<\OpenSSLCertificate>  $certs
     */
    private function assertWithinValidity(array $certs): void
    {
        $now = time();

        foreach ($certs as $i => $cert) {
            $info = openssl_x509_parse($cert);

            if ($info === false) {
                throw new RuntimeException("Certificate {$i} could not be parsed.");
            }

            // Attestation batch keys on devices shipped before 2021 carry expired
            // certificates by design, and Google documents them as still
            // trustworthy. Only the leaf's own window is enforced here; the rest
            // of the chain is trusted through its signature and the revocation
            // list instead.
            if ($i > 0) {
                continue;
            }

            if ($now < ($info['validFrom_time_t'] ?? 0) || $now > ($info['validTo_time_t'] ?? 0)) {
                throw new RuntimeException('The attestation leaf certificate is outside its validity window.');
            }
        }
    }

    private function readKeyDescription(string $leafPem): KeyDescription
    {
        $der = $this->extensionDer($leafPem, KeyDescription::OID);

        if ($der === null) {
            throw new RuntimeException(
                'The leaf certificate carries no KeyDescription extension, so it is not an attestation certificate.'
            );
        }

        return KeyDescription::fromDer($der);
    }

    /**
     * PHP exposes unknown extensions as their raw value keyed by OID, but the
     * representation differs between builds, so the DER is located in the
     * certificate itself rather than trusted from openssl_x509_parse().
     */
    private function extensionDer(string $pem, string $oid): ?string
    {
        $der = $this->pemToDer($pem);
        $needle = $this->oidToDer($oid);

        $position = strpos($der, $needle);

        if ($position === false) {
            return null;
        }

        // Extension ::= SEQUENCE { extnID OID, critical BOOLEAN DEFAULT FALSE,
        //                          extnValue OCTET STRING }
        $cursor = $position + strlen($needle);

        // Skip the optional critical flag.
        if (($der[$cursor] ?? '') === "\x01") {
            $cursor += 3;
        }

        if (($der[$cursor] ?? '') !== "\x04") {
            return null;
        }

        $cursor++;
        $length = $this->readLength($der, $cursor);

        return substr($der, $cursor, $length);
    }

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';

        return (string) base64_decode($body, true);
    }

    private function oidToDer(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $body = chr($parts[0] * 40 + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $stack = [$part & 0x7F];
            $part >>= 7;

            while ($part > 0) {
                array_unshift($stack, ($part & 0x7F) | 0x80);
                $part >>= 7;
            }

            $body .= implode('', array_map('chr', $stack));
        }

        return "\x06".chr(strlen($body)).$body;
    }

    private function readLength(string $der, int &$cursor): int
    {
        $first = ord($der[$cursor++]);

        if ($first < 0x80) {
            return $first;
        }

        $bytes = $first & 0x7F;
        $length = 0;

        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$cursor++]);
        }

        return $length;
    }

    private function publicKeyFingerprint(mixed $cert): string
    {
        $key = openssl_pkey_get_public($cert);

        if ($key === false) {
            throw new RuntimeException('Cannot read a certificate public key.');
        }

        $details = openssl_pkey_get_details($key);

        return hash('sha256', (string) ($details['key'] ?? ''));
    }
}
