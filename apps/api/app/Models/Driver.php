<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedNationalId;
use App\Support\Masker;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use HasUuids;

    protected $fillable = [
        'full_name',
        'employee_code',
        'national_id_encrypted',
        'national_id_hmac',
        'phone',
        'license_number',
        'license_expires_at',
        'status',
        'created_by',
        'terminated_at',
        'anonymized_at',
    ];

    protected $hidden = [
        'national_id_encrypted',
        'national_id_hmac',
        // Not a real column. Assigning $driver->national_id is an easy mistake
        // to make, and it would store the plaintext as an unmapped attribute
        // that skips the cast and serialises straight into JSON. Hiding the name
        // means the mistake costs nothing instead of leaking the number.
        'national_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'national_id_encrypted' => EncryptedNationalId::class,
            'license_expires_at' => 'date',
            'terminated_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * Reading this decrypts the national ID, which only works where
     * NATIONAL_ID_ENCRYPTION_KEY is mounted (portal/worker, not api).
     *
     * @return Attribute<string|null, never>
     */
    protected function maskedNationalId(): Attribute
    {
        return Attribute::get(fn (): ?string => Masker::nationalId($this->national_id_encrypted));
    }

    /** @return Attribute<string|null, never> */
    protected function maskedPhone(): Attribute
    {
        return Attribute::get(fn (): ?string => Masker::phone($this->phone));
    }

    /** @return HasMany<DriverDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<ActivationCode, $this> */
    public function activationCodes(): HasMany
    {
        return $this->hasMany(ActivationCode::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
