<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivationCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'token_hash',
        'driver_id',
        'created_by',
        'expires_at',
        'max_uses',
        'used_count',
        'revoked_at',
        'revoked_by',
        'note',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * Mirrors the column defaults so a freshly created model answers the same
     * as one read back. Without these, max_uses is null on the instance create()
     * returns and isUsable() reports a brand-new code as spent.
     */
    protected $attributes = [
        'max_uses' => 1,
        'used_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Whether the code can still be redeemed.
     *
     * Says nothing about the token: enrollment checks that separately, because a
     * code being usable and the presented token matching it are different
     * questions.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->used_count < $this->max_uses;
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
