<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every authenticated portal page must actually render.
 *
 * Checking only /staff/login proves nothing: it renders before authentication,
 * so a layout that depends on the signed-in user stays broken until someone
 * logs in. That is exactly how a missing display name went unnoticed while the
 * whole portal was unusable.
 */
final class PanelPagesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'admin'): Staff
    {
        $staff = new Staff(['email' => "{$role}@test.local", 'role' => $role]);
        $staff->password_hash = Hash::make('irrelevant');
        $staff->save();

        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/staff'],
            'drivers' => ['/staff/drivers'],
            'create driver' => ['/staff/drivers/create'],
            'activation codes' => ['/staff/activation-codes'],
            'devices' => ['/staff/devices'],
            'audit logs' => ['/staff/audit-logs'],
            'retention policies' => ['/staff/retention-policies'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_an_admin_can_open_every_page(string $path): void
    {
        $this->signIn('admin');

        $this->get($path)->assertOk();
    }

    /** Retention windows decide when data is destroyed, so registrars stay out (§10.2). */
    public function test_a_registrar_cannot_open_retention_policies(): void
    {
        $this->signIn('registrar');

        $this->get('/staff/retention-policies')->assertForbidden();
    }

    public function test_a_disabled_account_cannot_use_the_panel(): void
    {
        $staff = $this->signIn('admin');
        $staff->forceFill(['disabled_at' => now()])->save();

        $this->get('/staff/drivers')->assertForbidden();
    }
}
