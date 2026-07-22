<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\VerifyAppSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Tests\TestCase;

/**
 * Layer 1 of the trust model (PRD §2.3).
 *
 * Worth stating plainly: passing this proves almost nothing, because the
 * fingerprint is a public value readable from any published APK. These tests
 * only assert that the check behaves as specified — not that it provides
 * meaningful protection. That comes from VerifyDeviceSignature.
 */
final class VerifyAppSignatureTest extends TestCase
{
    private function pass(Request $request): HttpResponse
    {
        return (new VerifyAppSignature)->handle(
            $request,
            fn () => new Response('reached', 200),
        );
    }

    private function request(?string $signature): Request
    {
        $request = Request::create('/api/v1/health', 'GET');

        if ($signature !== null) {
            $request->headers->set('X-App-Signature', $signature);
        }

        return $request;
    }

    public function test_a_listed_fingerprint_passes(): void
    {
        config(['security.app_signature_allowlist' => ['AA:BB:CC']]);

        $this->assertSame(200, $this->pass($this->request('AA:BB:CC'))->getStatusCode());
    }

    public function test_an_unlisted_fingerprint_is_rejected(): void
    {
        config(['security.app_signature_allowlist' => ['AA:BB:CC']]);

        $response = $this->pass($this->request('DE:AD:BE'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('E_APP_SIGNATURE_INVALID', $response->getContent());
    }

    public function test_a_missing_header_is_rejected(): void
    {
        config(['security.app_signature_allowlist' => ['AA:BB:CC']]);

        $this->assertSame(403, $this->pass($this->request(null))->getStatusCode());
    }

    /**
     * Rotating the app signing key means old and new builds are in the field at
     * the same time. If only one fingerprint were accepted, every installed
     * device would start getting 403 on release day (§6.8).
     */
    public function test_several_fingerprints_are_accepted_at_once(): void
    {
        config(['security.app_signature_allowlist' => ['OLD:KEY', 'NEW:KEY']]);

        $this->assertSame(200, $this->pass($this->request('OLD:KEY'))->getStatusCode());
        $this->assertSame(200, $this->pass($this->request('NEW:KEY'))->getStatusCode());
    }

    public function test_comparison_ignores_case(): void
    {
        config(['security.app_signature_allowlist' => ['aa:bb:cc']]);

        $this->assertSame(200, $this->pass($this->request('AA:BB:CC'))->getStatusCode());
    }

    /**
     * An unconfigured allowlist is an incomplete deployment, not a permissive
     * one. Failing open in production would disable the check with nothing in
     * the logs to show for it.
     */
    public function test_an_empty_allowlist_blocks_in_production(): void
    {
        config(['security.app_signature_allowlist' => []]);
        app()->detectEnvironment(fn () => 'production');

        $this->assertSame(403, $this->pass($this->request('ANYTHING'))->getStatusCode());
    }

    public function test_an_empty_allowlist_is_permitted_outside_production(): void
    {
        config(['security.app_signature_allowlist' => []]);
        app()->detectEnvironment(fn () => 'local');

        $this->assertSame(200, $this->pass($this->request(null))->getStatusCode());
    }
}
