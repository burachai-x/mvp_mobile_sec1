<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Pages\RetentionPolicies;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\RetentionPolicy;
use App\Models\Staff;
use App\Support\Crypto\Hasher;
use App\Support\DocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Staff Portal side of masking + step-up PIN (architecture.md §9, §10.2).
 *
 * Elevation itself is covered by ElevationTest; what matters here is that the
 * screens actually go through it. A gate the UI can reach around is not a gate,
 * and the audit trail it was supposed to leave would never be written.
 */
final class PiiAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '481923';

    private const NATIONAL_ID = '1234567890123';

    private const LICENSE_NUMBER = 'AB1234567';

    private Staff $staff;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('garage');

        $this->staff = $this->staff();
        $this->driver = Driver::create([
            'full_name' => 'Somchai Test',
            'employee_code' => 'EMP-0001',
            // Check digit is deliberately wrong, so the Department of Provincial
            // Administration cannot have issued it to a real person.
            'national_id_encrypted' => self::NATIONAL_ID,
            'national_id_hmac' => Hasher::make()->hash(self::NATIONAL_ID),
            'phone' => '0812345678',
            'license_number' => self::LICENSE_NUMBER,
            'created_by' => $this->staff->getKey(),
        ]);
    }

    /** @param array<string, bool> $permissions */
    private function staff(array $permissions = ['can_view_pii' => true, 'can_download_documents' => true]): Staff
    {
        $staff = new Staff([
            'email' => 'registrar'.Staff::count().'@test.local',
            'role' => 'registrar',
            ...$permissions,
        ]);
        $staff->password_hash = Hash::make('login-password');
        $staff->data_access_pin_hash = Hash::make(self::PIN);
        $staff->save();

        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function document(): DriverDocument
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
            $this->driver,
            'national_id_copy',
            new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true),
        );
    }

    private function page(): Testable
    {
        return Livewire::test(ViewDriver::class, ['record' => $this->driver->getKey()]);
    }

    /**
     * Mounts an action, fills its modal and submits it.
     *
     * Filament's own callAction($name, $data) cannot be used here: everything it
     * fills goes through fillFormDataForTesting(), which returns early unless
     * app()->runningUnitTests(), and this suite runs with APP_ENV=local (see the
     * note in the accompanying report). Setting the state path directly is what
     * Livewire does for a real modal submit anyway.
     *
     * @param  array<string, string>  $data
     */
    private function submitAction(Testable $page, string $name, array $data = []): Testable
    {
        $page->call('mountAction', $name);

        foreach ($data as $field => $value) {
            $page->set("mountedActions.0.data.{$field}", $value);
        }

        return $page->call('callMountedAction');
    }

    public function test_the_page_shows_only_masked_values_until_the_pin_is_entered(): void
    {
        $this->page()
            ->assertSee('x-xxxx-xxxxx-x2-3')
            ->assertDontSee(self::NATIONAL_ID)
            ->assertDontSee(self::LICENSE_NUMBER);
    }

    /** Both buttons vanish for an account with neither permission — the page must still render. */
    public function test_the_page_renders_for_a_staff_member_with_no_pii_permissions(): void
    {
        $this->document();
        $this->staff(['can_view_pii' => false, 'can_download_documents' => false]);

        $this->get('/staff/drivers/'.$this->driver->getKey())->assertOk();
    }

    public function test_revealing_without_a_pin_is_refused(): void
    {
        $page = $this->submitAction($this->page(), 'revealPii');

        $page->assertHasErrors()->assertDontSee(self::NATIONAL_ID);

        $this->assertFalse($page->get('piiRevealed'));
        $this->assertSame(0, AuditLog::where('action', 'driver.pii_viewed')->count());
    }

    public function test_a_wrong_pin_reveals_nothing(): void
    {
        $page = $this->submitAction($this->page(), 'revealPii', ['pin' => '000000', 'reason' => 'Duplicate check']);

        $page->assertHasErrors()->assertDontSee(self::NATIONAL_ID);

        $this->assertFalse($page->get('piiRevealed'));
        $this->assertSame(0, AuditLog::where('action', 'driver.pii_viewed')->count());
        $this->assertSame(1, $this->staff->fresh()->pin_failed_count);
    }

    public function test_the_correct_pin_reveals_the_real_values(): void
    {
        $page = $this->submitAction($this->page(), 'revealPii', ['pin' => self::PIN, 'reason' => 'Duplicate check']);

        $page->assertHasNoErrors()
            ->assertSee(self::NATIONAL_ID)
            ->assertSee(self::LICENSE_NUMBER)
            ->assertSee('0812345678');

        $this->assertTrue($page->get('piiRevealed'));
    }

    /** A 10-minute window means nothing if the values sit on screen for the rest of the day (§9.2). */
    public function test_the_revealed_values_disappear_once_the_elevation_expires(): void
    {
        $page = $this->submitAction($this->page(), 'revealPii', ['pin' => self::PIN, 'reason' => 'Duplicate check']);

        $page->assertSee(self::NATIONAL_ID);

        $this->travel((int) config('security.staff_pin.elevation_ttl') + 1)->minutes();

        $page->refresh()->assertDontSee(self::NATIONAL_ID);
    }

    /**
     * One entry per reveal, tied to the elevation it happened under. Auditing
     * only the PIN would record that someone elevated, not whose record they
     * then opened — which is the question PDPA asks (§9.3).
     */
    public function test_revealing_writes_one_audited_access(): void
    {
        $this->submitAction($this->page(), 'revealPii', ['pin' => self::PIN, 'reason' => 'Court order 12/2569']);

        $entry = AuditLog::where('action', 'driver.pii_viewed')->sole();
        $elevationId = AuditLog::where('action', 'staff.elevated')->sole()->meta['elevation_id'];

        $this->assertSame('driver', $entry->subject_type);
        $this->assertSame($this->driver->getKey(), $entry->subject_id);
        $this->assertSame($elevationId, $entry->meta['elevation_id']);
        $this->assertSame('Court order 12/2569', $entry->meta['reason']);
        $this->assertSame(['national_id', 'phone', 'license_number'], $entry->meta['fields']);
    }

    /**
     * Permission and PIN are separate gates. This account types a correct PIN
     * and walks away with a real, live elevation — it just does not carry
     * view_pii, and no amount of retrying turns it into one that does.
     */
    public function test_a_staff_member_without_can_view_pii_cannot_reveal(): void
    {
        $document = $this->document();
        $this->staff(['can_view_pii' => false, 'can_download_documents' => true]);

        $page = $this->page();

        $this->submitAction($page, 'download_'.$document->getKey(), [
            'pin' => self::PIN,
            'reason' => 'Duplicate check',
        ])->assertFileDownloaded();

        $page->assertActionHidden('revealPii');

        $this->submitAction($page, 'revealPii')->assertDontSee(self::NATIONAL_ID);

        $this->assertFalse($page->get('piiRevealed'));
        $this->assertSame(0, AuditLog::where('action', 'driver.pii_viewed')->count());
    }

    /** The mirror image: a live elevation for view_pii unlocks no documents. */
    public function test_downloading_without_the_download_scope_is_refused(): void
    {
        $document = $this->document();
        $this->staff(['can_view_pii' => true, 'can_download_documents' => false]);

        $page = $this->page();

        $this->submitAction($page, 'revealPii', ['pin' => self::PIN, 'reason' => 'Duplicate check'])
            ->assertHasNoErrors();

        $page->assertActionHidden('download_'.$document->getKey());

        $this->submitAction($page, 'download_'.$document->getKey())->assertNoFileDownloaded();

        $this->assertSame(0, AuditLog::where('action', 'document.viewed')->count());
    }

    /**
     * Retention shreds the wrapped key, so the bytes left in Garage cannot be
     * read by anyone. Staff have to be told that, not shown a decryption error.
     */
    public function test_a_destroyed_document_cannot_be_downloaded(): void
    {
        $document = $this->document();
        DocumentStore::make()->destroy($document);

        $this->submitAction($this->page(), 'download_'.$document->getKey(), [
            'pin' => self::PIN,
            'reason' => 'Duplicate check',
        ])->assertNoFileDownloaded()->assertNotified();

        $this->assertSame(0, AuditLog::where('action', 'document.viewed')->count());
    }

    public function test_downloading_is_audited(): void
    {
        $document = $this->document();

        $this->submitAction($this->page(), 'download_'.$document->getKey(), [
            'pin' => self::PIN,
            'reason' => 'Duplicate check',
        ])->assertFileDownloaded("national_id_copy-{$document->getKey()}.jpg");

        $entry = AuditLog::where('action', 'document.viewed')->sole();
        $elevationId = AuditLog::where('action', 'staff.elevated')->sole()->meta['elevation_id'];

        $this->assertSame('driver_document', $entry->subject_type);
        $this->assertSame($document->getKey(), $entry->subject_id);
        $this->assertSame($elevationId, $entry->meta['elevation_id']);
        $this->assertSame('Duplicate check', $entry->meta['reason']);
    }

    /**
     * The bytes are decrypted for this one response only: an attachment any
     * cache is free to keep would put a plaintext ID card scan back on disk.
     */
    public function test_the_download_is_a_no_store_attachment_of_the_decrypted_file(): void
    {
        $document = $this->document();

        $method = new \ReflectionMethod(ViewDriver::class, 'download');
        $method->setAccessible(true);

        $response = $method->invoke(new ViewDriver, $document);

        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        $this->assertSame($document->sha256_plaintext, hash('sha256', $bytes));
    }

    /** Retention windows destroy data permanently, so saving needs the PIN too (§10.2). */
    public function test_saving_retention_policies_without_an_elevation_is_refused(): void
    {
        $this->staff()->forceFill(['role' => 'admin'])->save();

        Livewire::test(RetentionPolicies::class)
            ->set('data.activity_log_days', 7)
            ->call('save')
            ->assertNotified();

        $this->assertSame(90, RetentionPolicy::query()->whereKey('activity_log_days')->value('value_days'));
        $this->assertSame(0, AuditLog::where('action', 'retention_policy.updated')->count());
    }

    public function test_saving_retention_policies_under_an_elevation_is_audited(): void
    {
        $this->staff()->forceFill(['role' => 'admin'])->save();

        $page = Livewire::test(RetentionPolicies::class);

        $this->submitAction($page, 'elevate', ['pin' => self::PIN, 'reason' => 'Legal review 2569'])
            ->set('data.activity_log_days', 120)
            ->call('save');

        $this->assertSame(120, RetentionPolicy::query()->whereKey('activity_log_days')->value('value_days'));

        $entry = AuditLog::where('action', 'retention_policy.updated')->sole();

        $this->assertSame('activity_log_days', $entry->meta['key']);
        $this->assertSame(90, $entry->meta['from_days']);
        $this->assertSame(120, $entry->meta['to_days']);
    }
}
