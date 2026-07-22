<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\StaffAudit;
use App\Models\RetentionPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retention windows (architecture.md §10).
 *
 * This screen deletes data permanently, so it is admin-only and the range is
 * checked here as well as by the retention_days_sane CHECK constraint — typing
 * 3 instead of 30 would shred nearly every driver record (§10.2).
 *
 * @property-read Schema $form
 */
class RetentionPolicies extends Page
{
    use InteractsWithFormActions;

    protected string $view = 'filament.pages.retention-policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $title = 'Retention policies';

    private const MIN_DAYS = 7;

    private const MAX_DAYS = 3650;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->role === 'admin';
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(
                RetentionPolicy::query()
                    ->orderBy('key')
                    ->pluck('key')
                    ->map(fn (string $key): TextInput => TextInput::make($key)
                        ->label(Str::headline($key))
                        ->suffix('days')
                        ->required()
                        ->integer()
                        ->minValue(self::MIN_DAYS)
                        ->maxValue(self::MAX_DAYS))
                    ->all()
            )
            ->statePath('data');
    }

    public function save(): void
    {
        // TODO(pii): require an elevated (step-up PIN) session before saving (§10.2).
        $new = $this->form->getState();
        $old = $this->currentValues();

        DB::transaction(function () use ($new, $old): void {
            foreach ($new as $key => $days) {
                if ((int) $days === $old[$key]) {
                    continue;
                }

                RetentionPolicy::query()->whereKey($key)->update([
                    'value_days' => (int) $days,
                    'updated_by' => Filament::auth()->id(),
                    'updated_at' => now(),
                ]);

                StaffAudit::log('retention_policy.updated', 'retention_policy', $key, [
                    'from_days' => $old[$key],
                    'to_days' => (int) $days,
                ]);
            }
        });

        Notification::make()->title('Retention policies saved')->success()->send();
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save'),
        ];
    }

    /** @return array<string, int> */
    private function currentValues(): array
    {
        return RetentionPolicy::query()->orderBy('key')->pluck('value_days', 'key')->all();
    }
}
