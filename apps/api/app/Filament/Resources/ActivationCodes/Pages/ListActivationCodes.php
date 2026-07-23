<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivationCodes\Pages;

use App\Filament\Resources\ActivationCodes\ActivationCodeResource;
use App\Filament\Support\StaffAudit;
use App\Models\ActivationCode;
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
                    ->body('Press Show QR on the new row when the driver is in front of you.')),
        ];
    }

    /**
     * Creates the code itself. The QR comes later, from the row action.
     *
     * No token is signed here: it would have to be shown immediately or thrown
     * away, and a QR that appears once in a corner of the screen is a QR staff
     * will miss. Show QR mints one on demand instead (§6.2).
     *
     * @param  array<string, mixed>  $data
     */
    private function issue(array $data): ActivationCode
    {
        return DB::transaction(function () use ($data): ActivationCode {
            $code = ActivationCode::create([
                'code' => $this->generateCode(),
                // No QR has been shown yet, so there is no token to hash. The
                // code cannot be redeemed in this state, which is correct.
                'token_hash' => '',
                'driver_id' => $data['driver_id'],
                'created_by' => Filament::auth()->id(),
                'expires_at' => $data['expires_at'],
                'note' => $data['note'] ?? null,
            ]);

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
