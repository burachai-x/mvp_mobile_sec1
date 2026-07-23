<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Support\ElevatedAction;
use App\Filament\Support\StaffAudit;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Staff;
use App\Support\DocumentStore;
use App\Support\Elevation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    /** Exactly what "Reveal full details" uncovers, and what the audit entry claims. */
    public const REVEALED_FIELDS = ['national_id', 'phone', 'license_number'];

    /**
     * Locked because it decides whether unmasked personal data is rendered: a
     * client-writable property would let the browser skip the PIN entirely.
     */
    #[Locked]
    public bool $piiRevealed = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Every staff look at a driver's personal data is recorded, even though
        // this page only shows masked values (CLAUDE.md §6, architecture.md §9.3).
        StaffAudit::log('driver.viewed', 'driver', (string) $this->getRecord()->getKey(), [
            'fields' => ['masked'],
        ]);
    }

    /**
     * The full values stay on screen only while the elevation that unlocked them
     * is still live. Otherwise a 10-minute window (§9.2) would mean nothing: the
     * page would keep showing a national ID for as long as the tab stayed open.
     */
    public function showsFullDetails(): bool
    {
        return $this->piiRevealed && (new Elevation)->current($this->staff(), 'view_pii') !== null;
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->revealAction(),
            ...$this->downloadActions(),
        ];
    }

    private function revealAction(): Action
    {
        return ElevatedAction::make('revealPii', 'view_pii', function (): void {
            $this->piiRevealed = true;

            // Written per reveal, not per elevation: the elevation only shows
            // that a PIN was typed, this shows whose record was opened (§9.3).
            (new Elevation)->recordAccess(
                $this->staff(),
                'driver.pii_viewed',
                'driver',
                (string) $this->getRecord()->getKey(),
                ['fields' => self::REVEALED_FIELDS],
            );
        })->label('Reveal full details');
    }

    /** @return array<Action> */
    private function downloadActions(): array
    {
        /** @var Driver $driver */
        $driver = $this->getRecord();

        return $driver->documents
            ->map(fn (DriverDocument $document): Action => ElevatedAction::make(
                'download_'.$document->getKey(),
                'download_documents',
                fn (): ?StreamedResponse => $this->download($document),
            )->label('Download '.Str::headline($document->type)))
            ->all();
    }

    private function download(DriverDocument $document): ?StreamedResponse
    {
        if ($document->destroyed_at !== null) {
            Notification::make()
                ->title('This document was destroyed by retention')
                ->body('Its key is gone, so the stored file can no longer be decrypted by anyone.')
                ->danger()
                ->send();

            return null;
        }

        $bytes = DocumentStore::make()->retrieve($document);

        (new Elevation)->recordAccess(
            $this->staff(),
            'document.viewed',
            'driver_document',
            (string) $document->getKey(),
            ['driver_id' => (string) $document->driver_id, 'type' => $document->type],
        );

        $extension = match ($document->content_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            "{$document->type}-{$document->getKey()}.{$extension}",
            [
                // content_type is whatever the browser claimed at upload time, so
                // it is not trustworthy enough to serve back as the type. An
                // opaque type plus the attachment disposition keeps the file from
                // ever being rendered in the tab.
                'Content-Type' => 'application/octet-stream',
                // A decrypted ID card scan sitting in a disk cache or a proxy
                // undoes the point of encrypting it at rest (CLAUDE.md §6).
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function staff(): Staff
    {
        /** @var Staff $staff */
        $staff = Filament::auth()->user();

        return $staff;
    }
}
