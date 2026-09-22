<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\IntegrityReport;
use App\Models\Staff;
use App\Support\Crypto\Hasher;
use App\Support\DocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retention is the only control in this system that destroys data on purpose,
 * so what matters is not just that it fires but that it stops where it was
 * told to: the row survives, the audit trail survives, and anything still
 * inside its window is left alone (architecture.md §10.3).
 */
final class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('garage');

        $this->staff = new Staff([
            'email' => 'registrar@test.local',
            'role' => 'registrar',
            'can_view_pii' => true,
            'can_download_documents' => true,
        ]);
        $this->staff->password_hash = Hash::make('login-password');
        $this->staff->data_access_pin_hash = Hash::make('481923');
        $this->staff->save();

        // DocumentStore stamps uploaded_by from the authenticated staff member.
        $this->actingAs($this->staff, 'staff');
    }

    public function test_shreds_a_driver_and_documents_past_the_window(): void
    {
        $driver = $this->driver('EMP-0001', '1234567890123', terminatedDaysAgo: 60);
        $document = $this->document($driver);
        $hmac = $driver->national_id_hmac;

        $this->artisan('retention:apply')->assertSuccessful();

        $document->refresh();
        $this->assertNull($document->dek_wrapped, 'the wrapped DEK must be gone');
        $this->assertNotNull($document->destroyed_at);

        $driver->refresh();
        $this->assertNull($driver->national_id_encrypted);
        $this->assertSame('', $driver->full_name);
        $this->assertSame('', $driver->phone);
        $this->assertNull($driver->license_number);
        $this->assertNotNull($driver->anonymized_at);

        // Kept on purpose: a keyed hash, not the number, and without it a
        // terminated driver could re-apply without the system noticing.
        $this->assertSame($hmac, $driver->national_id_hmac);
    }

    /**
     * Dropping the rows would turn every audit_logs entry that references this
     * driver into a dangling pointer, which is the reason for crypto-shredding
     * in the first place.
     */
    public function test_keeps_the_rows_so_the_audit_trail_still_resolves(): void
    {
        $driver = $this->driver('EMP-0002', '1234567890124', terminatedDaysAgo: 60);
        $document = $this->document($driver);

        $this->artisan('retention:apply')->assertSuccessful();

        $this->assertDatabaseHas('drivers', ['id' => $driver->getKey()]);
        $this->assertDatabaseHas('driver_documents', ['id' => $document->getKey()]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'driver.anonymized',
            'subject_id' => $driver->getKey(),
            'actor_type' => 'system',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.deleted',
            'subject_id' => $driver->getKey(),
        ]);
    }

    public function test_leaves_a_driver_still_inside_the_window_alone(): void
    {
        $driver = $this->driver('EMP-0003', '1234567890125', terminatedDaysAgo: 5);
        $document = $this->document($driver);

        $this->artisan('retention:apply')->assertSuccessful();

        $document->refresh();
        $this->assertNotNull($document->dek_wrapped);
        $this->assertNull($document->destroyed_at);

        $driver->refresh();
        $this->assertSame('Somchai Test', $driver->full_name);
        $this->assertNull($driver->anonymized_at);
    }

    public function test_leaves_an_active_driver_alone(): void
    {
        $driver = $this->driver('EMP-0004', '1234567890126', terminatedDaysAgo: null);

        $this->artisan('retention:apply')->assertSuccessful();

        $driver->refresh();
        $this->assertSame('Somchai Test', $driver->full_name);
        $this->assertNull($driver->anonymized_at);
    }

    /**
     * activity_log_days and pii_access_log_days exist as settings but are not
     * enforced here: audit_logs is append-only, and PII access records have to
     * outlive the data they describe (CLAUDE.md §6).
     */
    public function test_never_removes_audit_logs_however_old(): void
    {
        $driver = $this->driver('EMP-0005', '1234567890127', terminatedDaysAgo: 60);

        $ancient = AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => $this->staff->getKey(),
            'action' => 'driver.pii_viewed',
            'subject_type' => 'driver',
            'subject_id' => $driver->getKey(),
            'meta' => ['reason' => 'test'],
            'created_at' => now()->subYears(5),
        ]);

        $this->artisan('retention:apply')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $ancient->getKey()]);
    }

    public function test_prunes_only_integrity_reports_past_the_window(): void
    {
        $device = $this->device();

        $stale = IntegrityReport::create(['device_id' => $device->getKey(), 'verdict' => ['rooted' => false]]);
        $stale->forceFill(['created_at' => now()->subDays(120)])->save();

        $recent = IntegrityReport::create(['device_id' => $device->getKey(), 'verdict' => ['rooted' => false]]);

        $this->artisan('retention:apply')->assertSuccessful();

        $this->assertDatabaseMissing('integrity_reports', ['id' => $stale->getKey()]);
        $this->assertDatabaseHas('integrity_reports', ['id' => $recent->getKey()]);
    }

    private function driver(string $code, string $nationalId, ?int $terminatedDaysAgo): Driver
    {
        return Driver::create([
            'full_name' => 'Somchai Test',
            'employee_code' => $code,
            // Check digit is deliberately wrong, so the Department of Provincial
            // Administration cannot have issued it to a real person.
            'national_id_encrypted' => $nationalId,
            'national_id_hmac' => Hasher::make()->hash($nationalId),
            'phone' => '0812345678',
            'license_number' => 'AB1234567',
            'status' => $terminatedDaysAgo === null ? 'active' : 'terminated',
            'terminated_at' => $terminatedDaysAgo === null ? null : now()->subDays($terminatedDaysAgo),
            'created_by' => $this->staff->getKey(),
        ]);
    }

    private function document(Driver $driver): DriverDocument
    {
        $image = imagecreatetruecolor(30, 30);
        imagefilledrectangle($image, 0, 0, 30, 30, imagecolorallocate($image, 10, 90, 160));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return DocumentStore::make()->store(
            $driver,
            'national_id_copy',
            new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true),
        );
    }

    private function device(): Device
    {
        $owner = $this->driver('EMP-0100', '1234567890999', terminatedDaysAgo: null);

        $code = ActivationCode::create([
            'code' => 'ABCD1234EFGH',
            'token_hash' => Hasher::make()->hash('token'),
            'driver_id' => $owner->getKey(),
            'created_by' => $this->staff->getKey(),
            'expires_at' => now()->addDay(),
        ]);

        return Device::create([
            'driver_id' => $owner->getKey(),
            'activation_code_id' => $code->getKey(),
            'device_uuid_hmac' => Hasher::make()->hash(uniqid('', true)),
            'platform' => 'android',
            'public_key' => 'AA',
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }
}
