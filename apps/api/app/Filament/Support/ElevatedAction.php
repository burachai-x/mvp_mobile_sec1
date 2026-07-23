<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Staff;
use App\Support\Elevation;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Builds an action that only runs under a live step-up elevation (§9.2).
 *
 * The PIN and the reason are asked for in the action's own modal instead of on a
 * separate screen, because sending staff away to elevate and back loses what
 * they were doing — which is how people end up holding an elevation open all
 * day, or writing the PIN on a sticky note.
 */
final class ElevatedAction
{
    /**
     * @param  string  $scope  the elevation scope the work needs: view_pii or download_documents
     * @param  Closure(): mixed  $run  what to do once the elevation is in hand
     */
    public static function make(string $name, string $scope, Closure $run): Action
    {
        return Action::make($name)
            // Filament refuses to mount a hidden action, so this does gate as
            // well as tidy up. It is not what the refusal rests on though —
            // permission and PIN are separate checks, and action() re-does both.
            ->visible(fn (): bool => self::isPermitted($scope))
            ->schema(fn (): array => self::isElevated($scope) ? [] : [
                TextInput::make('pin')
                    ->label('Data access PIN')
                    ->password()
                    ->required()
                    ->autocomplete(false),

                // Mandatory so that opening someone's record without a good
                // reason is something that has to be explained afterwards (§9.3).
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Recorded against every record opened under this elevation.'),
            ])
            ->action(function (array $data, mixed $livewire) use ($scope, $run): mixed {
                $staff = self::staff();
                $elevation = new Elevation;

                if ($elevation->current($staff, $scope) === null) {
                    try {
                        $elevation->grant($staff, (string) ($data['pin'] ?? ''), (string) ($data['reason'] ?? ''));
                    } catch (RuntimeException $exception) {
                        self::refuse($livewire, $exception->getMessage());
                    }

                    // A correct PIN only unlocks what the account is allowed to
                    // reach, which need not include this scope.
                    if ($elevation->current($staff, $scope) === null) {
                        self::refuse($livewire, 'This account is not permitted to do this.');
                    }
                }

                return $run();
            });
    }

    /**
     * Mirrors Elevation::scopesFor(), which is what actually decides the scopes.
     */
    private static function isPermitted(string $scope): bool
    {
        $staff = self::staff();

        return match ($scope) {
            'view_pii' => (bool) $staff->can_view_pii,
            'download_documents' => (bool) $staff->can_download_documents,
        };
    }

    private static function isElevated(string $scope): bool
    {
        return (new Elevation)->current(self::staff(), $scope) !== null;
    }

    /**
     * Reports the failure on the PIN field rather than as a notification, so the
     * modal stays open with what was typed and the reason is not lost.
     */
    private static function refuse(mixed $livewire, string $message): never
    {
        throw ValidationException::withMessages([
            'mountedActions.'.array_key_last($livewire->mountedActions).'.data.pin' => $message,
        ]);
    }

    private static function staff(): Staff
    {
        /** @var Staff $staff */
        $staff = Filament::auth()->user();

        return $staff;
    }
}
