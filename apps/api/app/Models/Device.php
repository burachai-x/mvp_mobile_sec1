<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Columns whose type static analysis cannot infer from casts() alone.
 *
 * @property Carbon|null $pin_locked_until
 */
class Device extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'driver_id',
        'activation_code_id',
        'device_uuid_hmac',
        'platform',
        'model',
        'os_version',
        'app_version',
        'public_key',
        'key_attestation',
        'status',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
        'enrolled_at',
        'last_seen_at',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key_attestation' => 'array',
            'pin_failed_count' => 'integer',
            'pin_locked_until' => 'datetime',
            'revoked_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The statuses that still occupy the one-device-per-driver slot, matching
     * the partial unique index `devices_one_active_per_driver`.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereIn('status', ['pending_pin', 'active', 'blocked']);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<ActivationCode, $this> */
    public function activationCode(): BelongsTo
    {
        return $this->belongsTo(ActivationCode::class);
    }

    /** @return HasMany<DeviceSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(DeviceSession::class);
    }

    /** @return HasMany<IntegrityReport, $this> */
    public function integrityReports(): HasMany
    {
        return $this->hasMany(IntegrityReport::class);
    }
}
