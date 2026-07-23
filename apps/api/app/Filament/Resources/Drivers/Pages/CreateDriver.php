<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Support\StaffAudit;
use App\Models\Driver;
use App\Support\Crypto\Hasher;
use App\Support\DocumentStore;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Keyed by driver_documents.type. Values are UploadedFile instances
        // rather than paths because the form fields use storeFiles(false).
        $documents = [
            'application_form' => $data['application_form'],
            'national_id_copy' => $data['national_id_copy'],
            'driver_license_copy' => $data['driver_license_copy'],
        ];

        $nationalId = (string) $data['national_id'];

        unset(
            $data['application_form'],
            $data['national_id_copy'],
            $data['driver_license_copy'],
            $data['national_id'],
        );

        // The driver row and its documents are created together: a driver whose
        // documents failed to store is not an approved driver, and a rejected
        // upload must not leave a half-registered record behind.
        //
        // DocumentStore validates the bytes, strips EXIF and encrypts before
        // anything reaches Garage — a rejected file throws here and rolls the
        // whole thing back (§8.3).
        return DB::transaction(function () use ($data, $nationalId, $documents): Driver {
            $driver = Driver::create([
                ...$data,
                // The cast encrypts this; the HMAC is what makes lookup and
                // duplicate detection possible without decrypting (§5).
                'national_id_encrypted' => $nationalId,
                'national_id_hmac' => Hasher::make()->hash($nationalId),
                'created_by' => Filament::auth()->id(),
            ]);

            StaffAudit::log('driver.created', 'driver', $driver->getKey());

            $store = DocumentStore::make();

            foreach ($documents as $type => $file) {
                $document = $store->store($driver, $type, $file);

                StaffAudit::log('document.uploaded', 'driver_document', $document->getKey(), [
                    'driver_id' => $driver->getKey(),
                    'type' => $type,
                ]);
            }

            return $driver;
        });
    }
}
