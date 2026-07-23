<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Staff;
use App\Support\DocumentStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff-entered driver registration (architecture.md §6.1).
 *
 * This is the only way a driver ever enters the system — there is no
 * self-registration — so it is also the only place ID card scans are ingested.
 */
final class DriverRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = new Staff([
            'email' => 'registrar@test.local',
            'role' => 'registrar',
            'can_view_pii' => true,
        ]);
        $this->staff->password_hash = Hash::make('irrelevant');
        $this->staff->save();

        $this->actingAs($this->staff, 'staff');
    }

    private function image(): UploadedFile
    {
        $img = imagecreatetruecolor(30, 30);
        imagefilledrectangle($img, 0, 0, 30, 30, imagecolorallocate($img, 10, 90, 160));

        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true);
    }

    /**
     * Calls the page's creation hook directly with the shape Filament hands it.
     *
     * Driving this through Livewire would mean staging TemporaryUploadedFile
     * objects through its upload pipeline, which exercises Livewire rather than
     * the registration logic under test. The trade-off is that these tests do
     * not prove the form *fields* are wired up — test_the_form_requires_all_three
     * covers that separately.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides = []): Driver
    {
        $page = new CreateDriver;

        $method = new \ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        /** @var Driver $driver */
        $driver = $method->invoke($page, array_merge([
            'full_name' => 'Somchai Test',
            'employee_code' => 'EMP-0001',
            // Check digit is deliberately wrong: the Department of Provincial
            // Administration cannot have issued this number, so it can never
            // collide with a real person's ID even if this file leaks.
            //
            // Safe here because these tests call handleRecordCreation directly
            // and bypass the form, where ThaiNationalId does validate the
            // checksum. A test that goes through the form needs a valid one.
            'national_id' => '1234567890123',
            'phone' => '0812345678',
            'application_form' => $this->image(),
            'national_id_copy' => $this->image(),
            'driver_license_copy' => $this->image(),
        ], $overrides));

        return $driver;
    }

    /**
     * Covers what submit() cannot: that the form itself builds and asks for all
     * three documents. Rendering is the cheapest way to catch a broken schema,
     * which would otherwise only surface when a registrar opens the page.
     */
    public function test_the_registration_form_renders_and_asks_for_all_three_documents(): void
    {
        $response = $this->get('/staff/drivers/create');

        $response->assertOk();

        foreach (['application_form', 'national_id_copy', 'driver_license_copy'] as $field) {
            $response->assertSee($field);
        }
    }

    public function test_staff_registration_stores_the_driver_and_all_three_documents(): void
    {
        $this->submit();

        $driver = Driver::sole();

        $this->assertSame('Somchai Test', $driver->full_name);
        $this->assertSame($this->staff->getKey(), $driver->created_by);
        $this->assertSame('active', $driver->status);

        $this->assertEqualsCanonicalizing(
            ['application_form', 'national_id_copy', 'driver_license_copy'],
            $driver->documents()->pluck('type')->all(),
        );
    }

    /**
     * The national ID must never be readable straight off the row — the column
     * holds ciphertext and only the HMAC is usable for lookups (§5).
     */
    public function test_the_national_id_is_not_stored_in_the_clear(): void
    {
        $this->submit();

        $raw = \DB::table('drivers')->select('national_id_encrypted', 'national_id_hmac')->first();

        $this->assertStringNotContainsString('1234567890123', (string) $raw->national_id_encrypted);
        $this->assertStringNotContainsString('1234567890123', (string) $raw->national_id_hmac);

        // Still readable through the model, where the cast decrypts it.
        $this->assertSame('1234567890123', Driver::sole()->national_id_encrypted);
    }

    /** Whoever reaches the bucket must get ciphertext, not ID card scans (ADR 0005). */
    public function test_documents_land_in_garage_encrypted(): void
    {
        $this->submit();

        foreach (DriverDocument::all() as $document) {
            $stored = Storage::disk('garage')->get($document->object_key);

            $this->assertNotNull($stored);
            // JPEG magic bytes must not survive encryption.
            $this->assertStringNotContainsString("\xFF\xD8\xFF", substr((string) $stored, 0, 16));

            // Readable again only through the key held in the row.
            $this->assertSame(
                $document->sha256_plaintext,
                hash('sha256', DocumentStore::make()->retrieve($document)),
            );
        }
    }

    public function test_registration_is_audited(): void
    {
        $this->submit();

        $this->assertSame(1, AuditLog::where('action', 'driver.created')->count());
        $this->assertSame(3, AuditLog::where('action', 'document.uploaded')->count());
    }

    /**
     * A rejected upload must not leave a half-registered driver behind: staff
     * would see the row, assume the paperwork is on file, and issue an
     * activation code for someone whose documents were never stored.
     */
    public function test_a_rejected_document_rolls_back_the_whole_registration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, '<?php system($_GET["c"]); ?>');
        $disguised = new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true);

        try {
            $this->submit(['driver_license_copy' => $disguised]);
            $this->fail('A disguised file should have aborted the registration.');
        } catch (\RuntimeException) {
            // DocumentStore rejects it; the transaction unwinds.
        }

        $this->assertSame(0, Driver::count());
        $this->assertSame(0, DriverDocument::count());
        $this->assertSame(0, AuditLog::where('action', 'driver.created')->count());
    }

    public function test_a_duplicate_national_id_is_refused(): void
    {
        $this->submit();

        // The HMAC has a unique index precisely so someone who left cannot
        // quietly re-register under a new employee code (§10.3).
        $this->expectException(UniqueConstraintViolationException::class);
        $this->submit(['employee_code' => 'EMP-0002']);
    }
}
