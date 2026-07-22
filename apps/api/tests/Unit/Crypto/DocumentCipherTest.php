<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Support\Crypto\DocumentCipher;
use RuntimeException;
use Tests\TestCase;

/**
 * Envelope encryption for driver documents (ADR 0005).
 *
 * These are the properties the whole document-storage design rests on: if any
 * of them stops holding, ID card scans are readable by whoever reaches the
 * bucket, or retention deletion silently stops deleting anything.
 */
final class DocumentCipherTest extends TestCase
{
    private function cipher(): DocumentCipher
    {
        return DocumentCipher::make();
    }

    public function test_round_trip_returns_the_original_bytes(): void
    {
        $plaintext = random_bytes(4096);
        $cipher = $this->cipher();

        $meta = $cipher->encrypt($plaintext);

        $this->assertSame($plaintext, $cipher->decrypt($meta['ciphertext'], $meta));
    }

    public function test_ciphertext_does_not_contain_the_plaintext(): void
    {
        $plaintext = str_repeat('NATIONAL-ID-SCAN', 64);

        $meta = $this->cipher()->encrypt($plaintext);

        $this->assertStringNotContainsString('NATIONAL-ID-SCAN', $meta['ciphertext']);
    }

    /**
     * Encrypting the same file twice must never produce the same bytes. A fresh
     * IV and DEK per call is what stops an observer with bucket access from
     * telling that two drivers submitted the same document.
     */
    public function test_encrypting_the_same_input_twice_differs(): void
    {
        $cipher = $this->cipher();
        $plaintext = 'identical input';

        $a = $cipher->encrypt($plaintext);
        $b = $cipher->encrypt($plaintext);

        $this->assertNotSame($a['ciphertext'], $b['ciphertext']);
        $this->assertNotSame($a['iv'], $b['iv']);
        $this->assertNotSame($a['dek_wrapped'], $b['dek_wrapped']);
    }

    /**
     * The DEK is per-file, so one file's key must be useless against another.
     * Without this, crypto-shredding one driver would leave the rest exposed to
     * anyone who kept a copy of the deleted key.
     */
    public function test_a_dek_from_one_document_cannot_open_another(): void
    {
        $cipher = $this->cipher();

        $first = $cipher->encrypt('first document');
        $second = $cipher->encrypt('second document');

        $mixed = $second;
        $mixed['dek_wrapped'] = $first['dek_wrapped'];
        $mixed['dek_iv'] = $first['dek_iv'];
        $mixed['dek_tag'] = $first['dek_tag'];

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($second['ciphertext'], $mixed);
    }

    /**
     * Retention deletion works by destroying dek_wrapped and nothing else — the
     * object may well still sit in Garage. If this ever succeeded, "deleted"
     * driver data would still be readable (§10.3).
     */
    public function test_destroying_the_wrapped_dek_makes_the_document_unrecoverable(): void
    {
        $cipher = $this->cipher();
        $meta = $cipher->encrypt('sensitive scan');

        $shredded = $meta;
        $shredded['dek_wrapped'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unrecoverable by design/');

        $cipher->decrypt($meta['ciphertext'], $shredded);
    }

    /**
     * GCM is authenticated encryption; a flipped byte must fail loudly rather
     * than decrypt to plausible garbage that then gets handed to a browser.
     */
    public function test_tampered_ciphertext_is_rejected(): void
    {
        $cipher = $this->cipher();
        $meta = $cipher->encrypt('original content');

        $tampered = $meta['ciphertext'];
        $tampered[0] = $tampered[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($tampered, $meta);
    }

    public function test_tampered_auth_tag_is_rejected(): void
    {
        $cipher = $this->cipher();
        $meta = $cipher->encrypt('original content');

        $meta['auth_tag'] = random_bytes(16);

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($meta['ciphertext'], $meta);
    }

    /** Detects a swapped-out object in Garage even if the row still matches. */
    public function test_hash_mismatch_is_reported(): void
    {
        $cipher = $this->cipher();
        $meta = $cipher->encrypt('original content');
        $meta['sha256_plaintext'] = hash('sha256', 'something else entirely');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not match its recorded hash/');

        $cipher->decrypt($meta['ciphertext'], $meta);
    }

    /**
     * The api container is not given DOCUMENT_KEK (ADR 0007). Code that ends up
     * needing it there must fail with an explanation, not a null-key crash.
     */
    public function test_missing_kek_explains_the_container_split(): void
    {
        config(['security.document_kek' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ADR 0007/');

        DocumentCipher::make()->encrypt('anything');
    }
}
