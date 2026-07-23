<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivationCodes;

use App\Filament\Resources\ActivationCodes\Pages\ListActivationCodes;
use App\Filament\Support\StaffAudit;
use App\Models\ActivationCode;
use App\Support\ActivationToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One-time codes that let a driver enroll a device (architecture.md §6.2).
 *
 * Every code belongs to an already-registered driver; there are no floating
 * codes, and a driver who still holds a usable device cannot be issued one —
 * the old device has to be revoked first (one driver, one device).
 */
class ActivationCodeResource extends Resource
{
    protected static ?string $model = ActivationCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('driver_id')
                ->label('Driver')
                ->relationship(
                    'driver',
                    'full_name',
                    fn (Builder $query): Builder => $query
                        ->where('status', 'active')
                        ->whereDoesntHave('devices', fn (Builder $devices) => $devices->usable()),
                )
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Only drivers without a usable device can be issued a code.'),

            DateTimePicker::make('expires_at')
                ->required()
                ->seconds(false)
                // startOfMinute() is load-bearing. With seconds hidden the browser
                // steps the field by 60s starting from `min`, so a `min` carrying
                // seconds puts every whole-minute value off the grid — the field
                // fails native validation and the Issue button silently does
                // nothing, with no request sent and no error shown.
                ->minDate(now()->startOfMinute())
                ->default(now()->addDays(7)->startOfMinute()),

            TextInput::make('note')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->copyable(),
                TextColumn::make('driver.full_name')->label('Driver')->searchable(),
                TextColumn::make('expires_at')->dateTime()->sortable(),
                TextColumn::make('used_count')->label('Used')->sortable(),
                TextColumn::make('revoked_at')->dateTime()->placeholder('-')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                static::showQrAction(),
                static::revokeAction(),
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
            'index' => ListActivationCodes::route('/'),
        ];
    }

    /**
     * Shows the driver's QR, minting the token it carries.
     *
     * Only a hash of the token is ever stored, so an existing QR cannot be
     * redisplayed — there is nothing to redisplay from. Opening this therefore
     * issues a fresh token for the same code and replaces the stored hash, which
     * is also what makes it a usable recovery path: staff who close the window,
     * or whose driver never scanned in time, press it again instead of revoking
     * and starting over.
     *
     * The cost is that any QR shown earlier stops working, which the modal says
     * plainly.
     */
    private static function showQrAction(): Action
    {
        return Action::make('showQr')
            ->label('Show QR')
            ->icon(Heroicon::OutlinedQrCode)
            ->modalHeading('Activation QR')
            ->modalDescription('Have the driver scan this now. Opening this again issues a new QR and stops this one working.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->visible(fn (ActivationCode $record): bool => $record->isUsable())
            // Minted while the modal renders rather than on submit: the QR has to
            // exist by the time staff see the window, and there is no second step
            // for them to take.
            ->modalContent(fn (ActivationCode $record) => view(
                'filament.notifications.activation-qr',
                ['token' => static::mintToken($record)],
            ));
    }

    private static function mintToken(ActivationCode $record): string
    {
        return DB::transaction(function () use ($record): string {
            $issued = ActivationToken::make()->issue(
                $record,
                (int) now()->diffInMinutes($record->expires_at),
            );

            $record->update(['token_hash' => $issued['token_hash']]);

            StaffAudit::log('activation_code.qr_shown', 'activation_code', $record->getKey());

            return $issued['token'];
        });
    }

    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The code stops working immediately. Issue a new one if the driver still needs to enroll.')
            ->visible(fn (ActivationCode $record): bool => $record->revoked_at === null)
            ->action(function (ActivationCode $record): void {
                DB::transaction(function () use ($record): void {
                    $record->update([
                        'revoked_at' => now(),
                        'revoked_by' => Filament::auth()->id(),
                    ]);

                    StaffAudit::log('activation_code.revoked', 'activation_code', $record->getKey());
                });
            });
    }
}
