<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Base64Binary;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    use HasUuids;

    // The table has created_at only; documents are never edited in place.
    public const UPDATED_AT = null;

    /**
     * The envelope-encryption fields are fillable because the upload service
     * writes the whole row at once — dropping any of them silently would make
     * the object unreadable forever.
     */
    protected $fillable = [
        'driver_id',
        'type',
        'object_key',
        'bucket',
        'sha256_plaintext',
        'content_type',
        'size_bytes',
        'dek_wrapped',
        'dek_iv',
        'dek_tag',
        'iv',
        'auth_tag',
        'kek_version',
        'uploaded_by',
        'destroyed_at',
    ];

    protected $hidden = [
        'object_key',
        'dek_wrapped',
        'dek_iv',
        'dek_tag',
        'iv',
        'auth_tag',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dek_wrapped' => Base64Binary::class,
            'dek_iv' => Base64Binary::class,
            'dek_tag' => Base64Binary::class,
            'iv' => Base64Binary::class,
            'auth_tag' => Base64Binary::class,
            'size_bytes' => 'integer',
            'kek_version' => 'integer',
            'created_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by');
    }
}
