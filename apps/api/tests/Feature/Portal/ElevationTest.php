<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Support\Elevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Step-up authentication before personal data (architecture.md §9.2).
 *
 * This is the gate in front of national IDs and ID card scans. If it can be
 * passed with a password alone, or stays open indefinitely, the masking
 * everywhere else stops meaning anything.
 */
final class ElevationTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '481923';

    private function staff(array $attributes = []): Staff
    {
        $staff = new Staff(array_merge([
            'email' => 'registrar@test.local',
            'role' => 'registrar',
            'can_view_pii' => true,
            'can_download_documents' => true,
        ], $attributes));

        $staff->password_hash = Hash::make('login-password');
        $staff->data_access_pin_hash = Hash::make(self::PIN);
        $staff->save();

        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_the_correct_pin_grants_a_scoped_elevation(): void
    {
        $staff = $this->staff();

        $granted = (new Elevation)->grant($staff, self::PIN, 'Verifying a resubmitted document');

        $this->assertEqualsCanonicalizing(['view_pii', 'download_documents'], $granted['scopes']);
        $this->assertNotNull((new Elevation)->current($staff, 'view_pii'));
    }

    public function test_a_wrong_pin_grants_nothing(): void
    {
        $staff = $this->staff();

        try {
            (new Elevation)->grant($staff, '000000', 'attempt');
            $this->fail('A wrong PIN must not elevate.');
        } catch (RuntimeException) {
        }

        $this->assertNull((new Elevation)->current($staff, 'view_pii'));
        $this->assertSame(1, $staff->fresh()->pin_failed_count);
    }

    /**
     * Permissions and PIN are separate gates. A registrar who may view details
     * but not download scans must not get the download scope just by holding a
     * valid PIN.
     */
    public function test_scopes_follow_permissions_not_the_pin(): void
    {
        $staff = $this->staff(['can_download_documents' => false]);

        $granted = (new Elevation)->grant($staff, self::PIN, 'reason');

        $this->assertSame(['view_pii'], $granted['scopes']);
        $this->assertNull((new Elevation)->current($staff, 'download_documents'));
    }

    /**
     * Refused before the PIN is checked: an account that cannot reach personal
     * data has nothing to elevate to, and letting it spend attempts would turn
     * this into a way to probe PINs against accounts that can never use them.
     */
    public function test_an_account_without_permissions_cannot_elevate(): void
    {
        $staff = $this->staff(['can_view_pii' => false, 'can_download_documents' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no personal-data permissions/');

        (new Elevation)->grant($staff, self::PIN, 'reason');

        $this->assertSame(0, $staff->fresh()->pin_failed_count);
    }

    public function test_elevation_expires(): void
    {
        $staff = $this->staff();
        (new Elevation)->grant($staff, self::PIN, 'reason');

        $this->travel((int) config('security.staff_pin.elevation_ttl') + 1)->minutes();

        $this->assertNull((new Elevation)->current($staff, 'view_pii'));
    }

    /** A session carried onto another account must not bring elevation with it. */
    public function test_elevation_does_not_transfer_to_another_staff_member(): void
    {
        $staff = $this->staff();
        (new Elevation)->grant($staff, self::PIN, 'reason');

        $other = new Staff(['email' => 'other@test.local', 'role' => 'registrar', 'can_view_pii' => true]);
        $other->password_hash = Hash::make('x');
        $other->save();

        $this->assertNull((new Elevation)->current($other, 'view_pii'));
    }

    public function test_repeated_wrong_pins_lock_the_account(): void
    {
        $staff = $this->staff();
        $max = (int) config('security.staff_pin.max_attempts');

        for ($i = 0; $i < $max; $i++) {
            try {
                (new Elevation)->grant($staff->fresh(), '000000', 'attempt');
            } catch (RuntimeException) {
            }
        }

        $this->assertNotNull($staff->fresh()->pin_locked_until);

        // Even the correct PIN must not work while locked.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/');
        (new Elevation)->grant($staff->fresh(), self::PIN, 'reason');
    }

    /**
     * Auditing the elevation alone would only show that someone typed a PIN.
     * PDPA asks what they then looked at, so every read is recorded separately
     * and carries the elevation it happened under (§9.3).
     */
    public function test_each_access_is_audited_against_its_elevation(): void
    {
        $staff = $this->staff();
        $granted = (new Elevation)->grant($staff, self::PIN, 'Checking a duplicate application');

        $driverId = (string) Str::uuid();
        $documentId = (string) Str::uuid();

        (new Elevation)->recordAccess($staff, 'driver.pii_viewed', 'driver', $driverId, ['fields' => ['national_id']]);
        (new Elevation)->recordAccess($staff, 'document.viewed', 'driver_document', $documentId);

        $entries = AuditLog::whereIn('action', ['driver.pii_viewed', 'document.viewed'])->get();

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame($granted['elevation_id'], $entry->meta['elevation_id']);
            $this->assertSame('Checking a duplicate application', $entry->meta['reason']);
        }
    }

    /**
     * Someone with the permission can still walk the whole driver list. That
     * cannot be prevented, only noticed — so crossing the hourly ceiling has to
     * leave something an alert can fire on (§9.4).
     */
    public function test_unusual_access_volume_is_reported(): void
    {
        $staff = $this->staff();
        (new Elevation)->grant($staff, self::PIN, 'reason');

        Log::spy();

        $threshold = (int) config('security.staff_pin.reveal_alert_per_hour');

        for ($i = 0; $i <= $threshold; $i++) {
            (new Elevation)->recordAccess(
                $staff, 'driver.pii_viewed', 'driver', (string) Str::uuid()
            );
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Unusual personal-data access volume'
                && $context['staff_id'] === $staff->getKey()
                && $context['action'] === 'driver.pii_viewed');
    }

    /** Volume is watched, never blocked: cutting off a busy shift is worse. */
    public function test_crossing_the_threshold_does_not_block_access(): void
    {
        $staff = $this->staff();
        (new Elevation)->grant($staff, self::PIN, 'reason');

        $threshold = (int) config('security.staff_pin.reveal_alert_per_hour');

        for ($i = 0; $i < $threshold + 5; $i++) {
            (new Elevation)->recordAccess($staff, 'driver.pii_viewed', 'driver', (string) Str::uuid());
        }

        $this->assertSame($threshold + 5, AuditLog::where('action', 'driver.pii_viewed')->count());
    }

    public function test_a_reason_is_always_carried_into_the_audit_trail(): void
    {
        $staff = $this->staff();
        (new Elevation)->grant($staff, self::PIN, 'Court order 12/2569');

        $entry = AuditLog::where('action', 'staff.elevated')->sole();

        $this->assertSame('Court order 12/2569', $entry->meta['reason']);
    }
}
