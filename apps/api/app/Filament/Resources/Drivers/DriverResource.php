<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drivers;

use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Support\ThaiNationalId;
use App\Models\Driver;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Drivers are entered by staff after paper documents have been verified; there
 * is no self-registration and no approval step (architecture.md §6.1).
 *
 * Records are never edited or deleted from here: a driver row is referenced by
 * audit_logs and by devices, and removal is a separate PDPA process that works
 * by crypto-shredding rather than DELETE (§10.3).
 */
class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Driver')->schema([
                TextInput::make('full_name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('employee_code')
                    ->required()
                    ->maxLength(50)
                    ->unique(Driver::class, 'employee_code'),

                TextInput::make('national_id')
                    ->label('National ID')
                    ->required()
                    ->length(13)
                    ->rule(new ThaiNationalId)
                    ->helperText('13 digits, no dashes.'),

                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(20),

                TextInput::make('license_number')
                    ->maxLength(50),

                DatePicker::make('license_expires_at'),
            ])->columns(2),

            Section::make('Documents')
                ->description('Scans of the paper application the driver submitted. All three are required.')
                ->schema([
                    self::documentUpload('application_form', 'Application form'),
                    self::documentUpload('national_id_copy', 'National ID copy'),
                    self::documentUpload('driver_license_copy', 'Driver license copy'),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('full_name'),
                TextEntry::make('employee_code'),
                TextEntry::make('masked_national_id')->label('National ID'),
                TextEntry::make('masked_phone')->label('Phone'),
                TextEntry::make('license_number')->placeholder('-'),
                TextEntry::make('license_expires_at')->date()->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('createdBy.email')->label('Registered by'),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(2),

            // Staff may confirm which documents are on file, but the contents
            // stay behind the download endpoint, which requires a step-up PIN (§9.1).
            Section::make('Documents on file')->schema([
                TextEntry::make('documents.type')->label('Types')->badge()->placeholder('None'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('employee_code')->searchable()->sortable(),
                // Masked accessors, never the raw columns (§9.1).
                TextColumn::make('masked_national_id')->label('National ID'),
                TextColumn::make('masked_phone')->label('Phone'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'view' => ViewDriver::route('/{record}'),
        ];
    }

    private static function documentUpload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->required()
            // Nothing is written to the container disk: documents may only ever
            // land in Garage, encrypted (CLAUDE.md §6).
            ->storeFiles(false)
            ->maxSize(10 * 1024)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
            ->helperText('JPEG, PNG or PDF. Maximum 10 MB.');
    }
}
