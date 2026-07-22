<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AuditLog extends Model
{
    // Rows are written once and never touched again, so there is no updated_at.
    public $timestamps = false;

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'ip',
        'meta',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * A database trigger already rejects UPDATE and DELETE. Failing here as well
     * turns a confusing SQLSTATE deep inside a transaction into an obvious error
     * at the call site (CLAUDE.md §6).
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('audit_logs is append-only: entries cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException(
            'audit_logs is append-only: entries cannot be deleted. '
            .'Retention removal is a separate approved job, not an Eloquent call.'
        );
    }
}
