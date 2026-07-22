<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\StaffAudit;
use App\Models\Device;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Enrolled devices. Staff never create these — a device appears when a driver
 * enrolls with an activation code (architecture.md §6.3).
 */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('driver.full_name')->label('Driver')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('model')->placeholder('-')->searchable(),
                TextColumn::make('os_version')->label('OS version')->placeholder('-'),
                TextColumn::make('last_seen_at')->dateTime()->placeholder('Never')->sortable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->recordActions([
                static::deleteDeviceAction(),
                static::resetPinAction(),
            ]);
    }

    /**
     * Revoked devices stay visible: §6.6 requires being able to say afterwards
     * which driver a lost device belonged to.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => ListDevices::route('/'),
        ];
    }

    /**
     * Labelled "Delete device" because that is what staff are told to do when a
     * driver loses a phone, but it is a revoke plus a soft delete. A real DELETE
     * would strand audit_logs entries and erase who held the device (§6.6).
     */
    private static function deleteDeviceAction(): Action
    {
        return Action::make('deleteDevice')
            ->label('Delete device')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Device $record): bool => $record->deleted_at === null)
            ->schema([
                Select::make('revoke_reason')
                    ->label('Reason')
                    ->options([
                        'lost' => 'Lost',
                        'stolen' => 'Stolen',
                        'replaced' => 'Replaced',
                        'resigned' => 'Resigned',
                        'other' => 'Other',
                    ])
                    ->required(),
            ])
            ->modalDescription('The device stops working immediately and the driver needs a new activation code.')
            ->action(function (Device $record, array $data): void {
                DB::transaction(function () use ($record, $data): void {
                    $record->update([
                        'status' => 'revoked',
                        'revoke_reason' => $data['revoke_reason'],
                        'revoked_at' => now(),
                        'revoked_by' => Filament::auth()->id(),
                    ]);

                    // Otherwise a stolen phone keeps refreshing its access token.
                    $record->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);

                    $record->delete();

                    StaffAudit::log('device.revoked', 'device', $record->getKey(), [
                        'reason' => $data['revoke_reason'],
                    ]);
                });
            });
    }

    /**
     * The device keypair is still valid, so only the PIN is cleared — the driver
     * sets a new one on the same phone (§6.7). Also unblocks a device that locked
     * itself out on failed PIN attempts.
     */
    private static function resetPinAction(): Action
    {
        return Action::make('resetPin')
            ->label('Reset PIN')
            ->icon(Heroicon::OutlinedLockOpen)
            ->requiresConfirmation()
            ->modalDescription('Confirm the driver\'s identity first. They will be asked to set a new PIN on the same device.')
            ->visible(fn (Device $record): bool => $record->deleted_at === null && $record->status !== 'revoked')
            ->action(function (Device $record): void {
                DB::transaction(function () use ($record): void {
                    // forceFill: the PIN columns are deliberately outside $fillable.
                    $record->forceFill([
                        'status' => 'pending_pin',
                        'pin_hash' => null,
                        'pin_failed_count' => 0,
                        'pin_locked_until' => null,
                    ])->save();

                    $record->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);

                    StaffAudit::log('pin.reset', 'device', $record->getKey(), [
                        'driver_id' => $record->driver_id,
                    ]);
                });
            });
    }
}
