<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivationCodes\Pages;

use App\Filament\Resources\ActivationCodes\ActivationCodeResource;
use App\Filament\Support\StaffAudit;
use App\Models\ActivationCode;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                ->using(fn (array $data): ActivationCode => $this->issue($data)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function issue(array $data): ActivationCode
    {
        return DB::transaction(function () use ($data): ActivationCode {
            $code = ActivationCode::create([
                'code' => $this->generateCode(),
                // TODO(enroll): issue signed ES256 QR JWT
                // The real flow signs { aud: 'enroll', exp: +15m, jti, code } with
                // the ES256 activation key, shows the JWT once as a QR code and
                // stores only sha256(<jwt>) here (architecture.md §6.2).
                // Until then this is the hash of a secret nobody holds, so the row
                // is complete but no token can redeem it.
                'token_hash' => hash('sha256', Str::random(64)),
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
