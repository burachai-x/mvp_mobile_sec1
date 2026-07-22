<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrityReport extends Model
{
    use HasUuids;

    // Reports are evidence of one moment; there is nothing to update later.
    public const UPDATED_AT = null;

    protected $fillable = [
        'device_id',
        'verdict',
        'risk_score',
        'action',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verdict' => 'array',
            'risk_score' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
