<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Staff;
use App\Support\Crypto\Hasher;
use App\Support\DocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The document ingest pipeline (architecture.md §8.3).
 *
 * These files are ID card and driving licence scans — the most damaging thing
 * this system holds, and the one loss that cannot be undone since nobody can
 * change their national ID number.
 */
final class DocumentStoreTest extends TestCase
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
            'employee_code' => 'EMP-TEST-1',
            'national_id_encrypted' => '1234567890123',
            'national_id_hmac' => Hasher::make()->hash('1234567890123'),
            'phone' => '0800000000',
            'created_by' => $staff->getKey(),
        ]);
    }

    private function jpeg(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 120, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    public function test_a_stored_document_round_trips(): void
    {
        $store = DocumentStore::make();
        $original = $this->jpeg();

        $document = $store->store($this->driver, 'national_id_copy', $this->upload($original, 'id.jpg', 'image/jpeg'));

        // Re-encoding changes the bytes, so compare what the pipeline recorded
        // rather than the input.
        $this->assertSame($document->sha256_plaintext, hash('sha256', $store->retrieve($document)));
    }

    /**
     * The whole point of ADR 0005: whoever reaches the bucket gets ciphertext.
     */
    public function test_what_lands_in_garage_is_not_the_file(): void
    {
        $store = DocumentStore::make();
        $original = $this->jpeg();

        $document = $store->store($this->driver, 'national_id_copy', $this->upload($original, 'id.jpg', 'image/jpeg'));

        $stored = Storage::disk('garage')->get($document->object_key);

        $this->assertNotSame($original, $stored);
        // A JPEG always starts FF D8 FF; ciphertext must not.
        $this->assertStringNotContainsString("\xFF\xD8\xFF", substr((string) $stored, 0, 16));
    }

    /**
     * Phone cameras write GPS coordinates into EXIF, so an uploaded ID card
     * photo can otherwise carry the driver's home address into storage.
     */
    public function test_exif_metadata_does_not_survive_ingest(): void
    {
        $store = DocumentStore::make();

        // A JPEG with an APP1/Exif segment carrying a recognisable marker.
        $jpeg = $this->jpeg();
        $exifPayload = "Exif\x00\x00".str_repeat('HOME-GPS-COORDINATES', 4);
        $app1 = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;
        $withExif = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

        $this->assertStringContainsString('HOME-GPS-COORDINATES', $withExif);

        $document = $store->store($this->driver, 'national_id_copy', $this->upload($withExif, 'id.jpg', 'image/jpeg'));

        $this->assertStringNotContainsString('HOME-GPS-COORDINATES', $store->retrieve($document));
    }

    /**
     * Appending a payload after the image data is a routine way to smuggle
     * something past a naive check while the file still opens as a picture.
     */
    public function test_data_appended_after_the_image_is_discarded(): void
    {
        $store = DocumentStore::make();
        $smuggled = $this->jpeg()."<?php echo 'PAYLOAD-MARKER'; ?>";

        $document = $store->store($this->driver, 'national_id_copy', $this->upload($smuggled, 'id.jpg', 'image/jpeg'));

        $this->assertStringNotContainsString('PAYLOAD-MARKER', $store->retrieve($document));
    }

    /**
     * Content-Type and filename are both attacker-controlled, so detection has
     * to read the bytes.
     */
    public function test_a_disguised_file_type_is_rejected(): void
    {
        $store = DocumentStore::make();
        $notAnImage = $this->upload('<?php system($_GET["c"]); ?>', 'id.jpg', 'image/jpeg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rejected file type/');

        $store->store($this->driver, 'national_id_copy', $notAnImage);
    }

    public function test_a_pdf_carrying_javascript_is_rejected(): void
    {
        $store = DocumentStore::make();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Action /S /JavaScript /JS (app.alert('x')) >>\nendobj\n%%EOF";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/JavaScript/');

        $store->store($this->driver, 'application_form', $this->upload($pdf, 'form.pdf', 'application/pdf'));
    }

    /**
     * Retention deletes the key, not necessarily the object. If the file stayed
     * readable afterwards, "deleted" driver data would still be recoverable (§10.3).
     */
    public function test_destroying_a_document_makes_it_unreadable(): void
    {
        $store = DocumentStore::make();
        $document = $store->store($this->driver, 'national_id_copy', $this->upload($this->jpeg(), 'id.jpg', 'image/jpeg'));

        $store->destroy($document);

        $this->assertNotNull($document->fresh()->destroyed_at);
        $this->assertNull($document->fresh()->dek_wrapped);

        $this->expectException(RuntimeException::class);
        $store->retrieve($document->fresh());
    }

    public function test_object_key_carries_no_driver_identifier(): void
    {
        $store = DocumentStore::make();
        $document = $store->store($this->driver, 'national_id_copy', $this->upload($this->jpeg(), 'id.jpg', 'image/jpeg'));

        // A key that encodes who the file belongs to leaks that on its own, and
        // a predictable one undermines the private bucket (§8.4).
        $this->assertStringNotContainsString($this->driver->getKey(), $document->object_key);
        $this->assertStringNotContainsString('EMP-TEST-1', $document->object_key);
        $this->assertStringNotContainsString('1234567890123', $document->object_key);
    }
}
