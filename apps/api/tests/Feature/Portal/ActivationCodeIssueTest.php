<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Resources\ActivationCodes\Pages\ListActivationCodes;
use App\Models\ActivationCode;
use App\Models\Driver;
use App\Models\Staff;
use App\Support\ActivationToken;
use App\Support\Crypto\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Issuing an activation code (architecture.md §6.2).
 *
 * The QR handed to a driver has to be redeemable, and the row left behind has to
 * be useless on its own. Getting either half wrong is invisible until someone
 * actually tries to enroll.
 */
final class ActivationCodeIssueTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = new Staff(['email' => 'registrar@test.local', 'role' => 'registrar']);
        $staff->password_hash = Hash::make('irrelevant');
        $staff->save();

        $this->actingAs($staff, 'staff');

        $this->driver = Driver::create([
            'full_name' => 'Test Driver',
            'employee_code' => 'EMP-QR-1',
            'national_id_encrypted' => '1234567890123',
            'national_id_hmac' => Hasher::make()->hash('1234567890123'),
            'phone' => '0800000000',
            'created_by' => $staff->getKey(),
        ]);
    }

    private function issue(): array
    {
        $page = new ListActivationCodes();

        $method = new \ReflectionMethod($page, 'issue');
        $method->setAccessible(true);

        $code = $method->invoke($page, [
            'driver_id' => $this->driver->getKey(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $token = new \ReflectionProperty($page, 'issuedToken');
        $token->setAccessible(true);

        return [$code, (string) $token->getValue($page)];
    }

    public function test_the_issued_token_verifies_against_the_stored_row(): void
    {
        [$code, $token] = $this->issue();

        $claims = ActivationToken::make()->verify($token);

        $this->assertSame((string) $code->getKey(), $claims['jti']);
        $this->assertSame($code->code, $claims['code']);
        $this->assertTrue(ActivationToken::make()->matchesStoredHash($token, $code->fresh()->token_hash));
    }

    /**
     * A leaked database must not hand out usable codes: only the hash is kept,
     * and the token itself exists solely on the QR the driver was shown.
     */
    public function test_the_row_stores_only_a_hash(): void
    {
        [$code, $token] = $this->issue();

        $stored = ActivationCode::findOrFail($code->getKey());

        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(64, strlen($stored->token_hash));
        $this->assertStringNotContainsString(substr($token, 0, 24), $stored->token_hash);
    }

    /** Two codes must never be interchangeable, even issued back to back. */
    public function test_each_issue_produces_a_distinct_token(): void
    {
        [$first, $firstToken] = $this->issue();
        [$second, $secondToken] = $this->issue();

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertNotSame($first->code, $second->code);
        $this->assertFalse(
            ActivationToken::make()->matchesStoredHash($firstToken, $second->fresh()->token_hash),
            'A token must not validate against a different code.'
        );
    }

    public function test_the_token_carries_the_row_expiry(): void
    {
        [$code, $token] = $this->issue();

        // Decoding succeeds now; the expiry is enforced by ActivationToken and
        // covered there. What matters here is that the two do not drift apart.
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
}
