<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AuditLog;
use Filament\Facades\Filament;

/**
 * Writes the audit entries the Staff Portal is required to produce (CLAUDE.md §6).
 *
 * Callers are responsible for running this inside the same transaction as the
 * change it describes, so a rolled-back action leaves no entry claiming it happened.
 */
final class StaffAudit
{
    /** @param array<string, mixed> $meta */
    public static function log(string $action, string $subjectType, ?string $subjectId, array $meta = []): void
    {
        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => Filament::auth()->id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => request()->ip(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
