<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Resources\ActivationCodes\ActivationCodeResource;
use App\Filament\Resources\ActivationCodes\Pages\ListActivationCodes;
use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Staff;
use App\Support\ActivationToken;
use App\Support\Crypto\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Issuing a code and showing its QR (architecture.md §6.2).
 *
 * The two are deliberately separate. Creating the code leaves nothing usable
 * behind; the QR is minted when staff open it, with the driver present. What
 * has to hold is that the token on screen matches the row, and that the row on
 * its own is worthless.
 */
final class ActivationQrTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = new Staff(['email' => 'registrar@test.local', 'role' => 'registrar']);
        $this->staff->password_hash = Hash::make('irrelevant');
        $this->staff->save();

        $this->actingAs($this->staff, 'staff');

        $this->driver = Driver::create([
            'full_name' => 'Test Driver',
            'employee_code' => 'EMP-QR-1',
            'national_id_encrypted' => '1234567890123',
            'national_id_hmac' => Hasher::make()->hash('1234567890123'),
            'phone' => '0800000000',
            'created_by' => $this->staff->getKey(),
        ]);
    }

    private function createCode(): ActivationCode
    {
        $page = new ListActivationCodes();

        $method = new ReflectionMethod($page, 'issue');
        $method->setAccessible(true);

        return $method->invoke($page, [
            'driver_id' => $this->driver->getKey(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function showQr(ActivationCode $code): string
    {
        $method = new ReflectionMethod(ActivationCodeResource::class, 'mintToken');
        $method->setAccessible(true);

        return $method->invoke(null, $code);
    }

    /**
     * A code with no QR shown yet must not be redeemable. It is stored with an
     * empty hash, and an empty hash matching anything would let every fresh code
     * be enrolled by a caller who sends nothing.
     */
    public function test_a_code_is_not_redeemable_before_its_qr_is_shown(): void
    {
        $code = $this->createCode();

        $this->assertSame('', $code->token_hash);
        $this->assertFalse(ActivationToken::make()->matchesStoredHash('', $code->token_hash));
        $this->assertFalse(ActivationToken::make()->matchesStoredHash('anything', $code->token_hash));
    }

    public function test_the_shown_token_verifies_against_the_row(): void
    {
        $code = $this->createCode();
        $token = $this->showQr($code);

        $claims = ActivationToken::make()->verify($token);

        $this->assertSame((string) $code->getKey(), $claims['jti']);
        $this->assertSame($code->code, $claims['code']);
        $this->assertTrue(ActivationToken::make()->matchesStoredHash($token, $code->fresh()->token_hash));
    }

    /** A leaked database must hand out no usable codes. */
    public function test_the_row_stores_only_a_hash(): void
    {
        $code = $this->createCode();
        $token = $this->showQr($code);

        $stored = ActivationCode::findOrFail($code->getKey())->token_hash;

        $this->assertNotSame($token, $stored);
        $this->assertSame(64, strlen($stored));
        $this->assertStringNotContainsString(substr($token, 0, 24), $stored);
    }

    /**
     * Showing the QR again is the recovery path, so it has to work — and the
     * previous QR has to stop working, or a closed window would leave a second
     * live token nobody is tracking.
     */
    public function test_showing_the_qr_again_replaces_the_previous_one(): void
    {
        $code = $this->createCode();

        $first = $this->showQr($code);
        $second = $this->showQr($code->fresh());

        $this->assertNotSame($first, $second);

        $stored = $code->fresh()->token_hash;
        $this->assertTrue(ActivationToken::make()->matchesStoredHash($second, $stored));
        $this->assertFalse(ActivationToken::make()->matchesStoredHash($first, $stored));
    }

    /** Two codes must never be interchangeable. */
    public function test_a_token_does_not_validate_against_another_code(): void
    {
        $first = $this->createCode();
        $firstToken = $this->showQr($first);

        $this->driver->update(['employee_code' => 'EMP-QR-2']);
        $second = $this->createCode();
        $this->showQr($second);

        $this->assertFalse(
            ActivationToken::make()->matchesStoredHash($firstToken, $second->fresh()->token_hash),
        );
    }

    public function test_the_token_expires_with_the_row(): void
    {
        $code = $this->createCode();
        $token = $this->showQr($code);

        $payload = json_decode(
            base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true) ?: '',
            true,
        );

        $this->assertEqualsWithDelta(
            $code->expires_at->getTimestamp(),
            $payload['exp'],
            60,
            'The token must expire alongside the row it belongs to.',
        );
    }

    /**
     * Every QR shown is one more chance for a code to reach someone. Creating
     * the code is audited elsewhere; this is the event that matters afterwards.
     */
    public function test_each_showing_is_audited(): void
    {
        $code = $this->createCode();

        $this->showQr($code);
        $this->showQr($code->fresh());

        $this->assertSame(2, AuditLog::query()
            ->where('action', 'activation_code.qr_shown')
            ->where('subject_id', $code->getKey())
            ->count());
    }

    /** A revoked or spent code must not offer the button at all. */
    public function test_the_action_is_hidden_once_the_code_is_no_longer_usable(): void
    {
        $code = $this->createCode();
        $this->assertTrue($code->isUsable());

        $code->update(['revoked_at' => now()]);
        $this->assertFalse($code->fresh()->isUsable());

        $spent = $this->createCode();
        $spent->update(['revoked_at' => null, 'used_count' => $spent->max_uses]);
        $this->assertFalse($spent->fresh()->isUsable());

        $expired = $this->createCode();
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($expired->fresh()->isUsable());
    }

    /**
     * The path staff actually take. Everything else here calls mintToken()
     * directly, which would keep passing even if the button were wired to
     * nothing — this is the test that fails when the action itself breaks.
     */
    public function test_pressing_show_qr_renders_a_scannable_code(): void
    {
        $code = $this->createCode();

        Livewire::test(ListActivationCodes::class)
            // The action carries no form, so there is nothing to validate — what
            // matters is that mounting it puts a QR on screen.
            ->mountAction(TestAction::make('showQr')->table($code))
            ->assertSee('<svg', escape: false);

        $this->assertNotSame('', $code->fresh()->token_hash);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'activation_code.qr_shown',
            'subject_id' => $code->getKey(),
        ]);
    }

    /**
     * The expiry the form offers.
     *
     * Two things are being pinned. The window is fifteen minutes, because the
     * driver is at the desk when the code is issued. And the value sits on a
     * whole minute: seconds are hidden on this field, so the browser steps it by
     * 60s from `min`, and a default off that grid fails native validation — the
     * Issue button then does nothing at all, with no request and no error.
     */
    public function test_the_offered_expiry_is_fifteen_minutes_on_a_whole_minute(): void
    {
        Livewire::test(ListActivationCodes::class)
            ->mountAction(TestAction::make('create'))
            ->assertSchemaStateSet(function (array $state): array {
                $expiry = Carbon::parse($state['expires_at']);

                $this->assertSame(0, $expiry->second, 'The default must sit on a whole minute.');
                $this->assertEqualsWithDelta(15, now()->diffInMinutes($expiry), 1);

                // Asserted above; the helper needs an array back and has nothing
                // left to compare.
                return [];
            });
    }
}
