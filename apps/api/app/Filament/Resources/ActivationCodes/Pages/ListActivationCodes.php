<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivationCodes\Pages;

use App\Filament\Resources\ActivationCodes\ActivationCodeResource;
use App\Filament\Support\StaffAudit;
use App\Models\ActivationCode;
use App\Support\ActivationToken;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListActivationCodes extends ListRecords
{
    protected static string $resource = ActivationCodeResource::class;

    /** Ambiguous characters (0/O, 1/I) are left out: staff read these aloud. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Issue code')
                ->modalSubmitActionLabel('Issue')
                ->using(fn (array $data): ActivationCode => $this->issue($data))
                ->successNotification(fn (): Notification => Notification::make()
                    ->success()
                    ->title('Activation code issued')
                    ->body('Show this QR to the driver now. It cannot be displayed again.')
                    ->persistent()
                    // The view is on the safe list in AppServiceProvider, without
                    // which Filament drops it on the way to the browser and the
                    // notification arrives looking merely plain, with no QR.
                    ->view('filament.notifications.activation-qr', [
                        'token' => $this->issuedToken,
                    ])),
        ];
    }

    /**
     * The signed token, alive only for the request that created it.
     *
     * Deliberately not stored: the QR is the single copy handed to the driver,
     * and a code that can be redisplayed is a code that can be redeemed twice.
     */
    private ?string $issuedToken = null;

    /** @param array<string, mixed> $data */
    private function issue(array $data): ActivationCode
    {
        return DB::transaction(function () use ($data): ActivationCode {
            $code = ActivationCode::create([
                'code' => $this->generateCode(),
                // Overwritten below: the token can only be signed once the row
                // exists, because its jti is the row's id.
                'token_hash' => '',
                'driver_id' => $data['driver_id'],
                'created_by' => Filament::auth()->id(),
                'expires_at' => $data['expires_at'],
                'note' => $data['note'] ?? null,
            ]);

            $issued = ActivationToken::make()->issue(
                $code,
                (int) now()->diffInMinutes($code->expires_at),
            );

            // Only the hash is kept. A database leak then hands out no usable
            // codes, and the token itself exists in exactly one place: the QR
            // shown to the driver right now (§6.2).
            $code->update(['token_hash' => $issued['token_hash']]);

            // Held for this request only so the notification can render the QR.
            // Nothing persists it — reopening the page cannot show it again.
            $this->issuedToken = $issued['token'];

            StaffAudit::log('activation_code.created', 'activation_code', $code->getKey(), [
                'driver_id' => $data['driver_id'],
            ]);

            return $code;
        });
    }

    private function generateCode(): string
    {
        $body = '';

        for ($i = 0; $i < 8; $i++) {
            $body .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return substr($body, 0, 4).'-'.substr($body, 4);
    }
}
