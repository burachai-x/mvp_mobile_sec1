<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Support\StaffAudit;
use Filament\Resources\Pages\ViewRecord;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Every staff look at a driver's personal data is recorded, even though
        // this page only shows masked values (CLAUDE.md §6, architecture.md §9.3).
        StaffAudit::log('driver.viewed', 'driver', (string) $this->getRecord()->getKey(), [
            'fields' => ['masked'],
        ]);
    }
}
