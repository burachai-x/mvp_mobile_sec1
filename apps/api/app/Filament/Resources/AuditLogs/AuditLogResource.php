<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Staff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only view of the append-only audit trail.
 *
 * There is deliberately no create, edit or delete: the table has a database
 * trigger rejecting UPDATE and DELETE, and offering the buttons would only
 * produce errors (CLAUDE.md §6).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('action')->badge()->searchable(),
                TextColumn::make('actor_type'),
                TextColumn::make('actor_id')->label('Actor')->placeholder('-'),
                TextColumn::make('subject_type')->placeholder('-'),
                TextColumn::make('subject_id')->label('Subject')->placeholder('-'),
                TextColumn::make('ip')->label('IP')->placeholder('-'),
                // meta never holds raw PII or secrets, only reason / fields /
                // elevation_id — see the audit_logs migration.
                TextColumn::make('meta')->limit(60)->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),

                SelectFilter::make('actor_id')
                    ->label('Actor (staff)')
                    ->searchable()
                    ->options(fn (): array => Staff::query()
                        ->orderBy('email')
                        ->pluck('email', 'id')
                        ->all()),
            ]);
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
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
