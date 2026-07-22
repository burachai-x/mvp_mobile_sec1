<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Support\StaffAudit;
use App\Models\Driver;
use App\Support\Crypto\Hasher;
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

        $driver = DB::transaction(function () use ($data, $nationalId): Driver {
            $driver = Driver::create([
                ...$data,
                // The cast encrypts this; the HMAC is what makes lookup and
                // duplicate detection possible without decrypting (§5).
                'national_id_encrypted' => $nationalId,
                'national_id_hmac' => Hasher::make()->hash($nationalId),
                'created_by' => Filament::auth()->id(),
            ]);

            StaffAudit::log('driver.created', 'driver', $driver->getKey());

            return $driver;
        });

        // TODO(enroll): hand off to DocumentCipher + Garage disk
        // $documents carries the three UploadedFile objects. Encrypting them,
        // uploading the ciphertext and writing driver_documents (plus a
        // document.uploaded audit entry each) is being implemented separately.
        // Nothing here may write a document to the container disk (CLAUDE.md §6).

        return $driver;
    }
}
