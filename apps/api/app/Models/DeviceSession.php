<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Columns whose type static analysis cannot infer from casts() alone.
 *
 * @property Carbon $expires_at
 */
class DeviceSession extends Model
{
    use HasUuids;

    // The table tracks its own lifecycle through issued_at / expires_at / revoked_at.
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'refresh_token_hash',
        'access_jti',
        'ip',
        'user_agent',
        'issued_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'refresh_token_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
