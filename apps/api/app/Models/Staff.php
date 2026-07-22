<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Staff extends Authenticatable implements FilamentUser
{
    use HasUuids;

    // Laravel would guess "staffs"
    protected $table = 'staff';

    /**
     * The column is password_hash rather than password, so the auth guard has to
     * be told where to look; otherwise every login fails with an empty hash.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Needed in addition to getAuthPassword(): EloquentUserProvider's automatic
     * rehash writes to the column this returns. Left at the default it would
     * try to update a `password` column that does not exist.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Disabled accounts keep their rows so audit_logs.actor_id stays resolvable,
     * but must not be able to sign in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * Secrets are deliberately absent: they must be assigned explicitly, never
     * from a request array.
     */
    protected $fillable = [
        'email',
        'role',
        'can_view_pii',
        'can_download_documents',
        'last_login_at',
        'disabled_at',
    ];

    protected $hidden = [
        'password_hash',
        'totp_secret',
        'data_access_pin_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'totp_confirmed_at' => 'datetime',
            'pin_failed_count' => 'integer',
            'pin_locked_until' => 'datetime',
            'can_view_pii' => 'bool',
            'can_download_documents' => 'bool',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return HasMany<Driver, $this> */
    public function driversCreated(): HasMany
    {
        return $this->hasMany(Driver::class, 'created_by');
    }
}
