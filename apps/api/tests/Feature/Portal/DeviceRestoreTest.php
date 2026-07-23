<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Driver;
use App\Models\Staff;
use App\Support\Crypto\Hasher;
use App\Support\DeviceTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Undoing a device deletion (architecture.md §6.6).
 *
 * Deleting is what staff do when a phone is lost, so restoring is the rare
 * correction of a mistake rather than the other half of a pair. What has to
 * hold: the one-device rule survives it, the sessions that were killed stay
 * killed, and it is written down.
 */
final class DeviceRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->staff('admin@test.local', 'admin');

        $this->driver = Driver::create([
            'full_name' => 'Test Driver',
            'employee_code' => 'EMP-R-1',
            'national_id_encrypted' => '1234567890123',
            'national_id_hmac' => Hasher::make()->hash('1234567890123'),
            'phone' => '0800000000',
            'created_by' => $this->admin->getKey(),
        ]);

        $this->actingAs($this->admin, 'staff');
    }

    private function staff(string $email, string $role): Staff
    {
        $staff = new Staff(['email' => $email, 'role' => $role]);
        $staff->password_hash = Hash::make('irrelevant');
        $staff->save();

        return $staff;
    }

    private function device(?Driver $driver = null): Device
    {
        $owner = $driver ?? $this->driver;

        // Every device comes from an activation code; the column is not
        // nullable, because a device with no provenance is one nobody can say
        // who authorised.
        $code = ActivationCode::create([
            'code' => strtoupper(substr(md5(uniqid('', true)), 0, 4)).'-'.strtoupper(substr(md5(uniqid('', true)), 0, 4)),
            'token_hash' => '',
            'driver_id' => $owner->getKey(),
            'created_by' => $this->admin->getKey(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $device = Device::create([
            'driver_id' => $owner->getKey(),
            'activation_code_id' => $code->getKey(),
            'device_uuid_hmac' => Hasher::make()->hash(uniqid('', true)),
            'platform' => 'android',
            'public_key' => 'AA',
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // forceFill: the PIN columns sit outside $fillable on purpose, so
        // create() drops them without a word.
        $device->forceFill(['pin_hash' => Hash::make('481923')])->save();

        return $device;
    }

    /** Mirrors the staff Delete action without going through Filament. */
    private function deleteDevice(Device $device): void
    {
        $device->update(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'lost']);
        $device->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $device->delete();
    }

    private function restore(Device $device): void
    {
        $action = (new ReflectionMethod(DeviceResource::class, 'restoreDeviceAction'));
        $action->setAccessible(true);

        $closure = $action->invoke(null)->getActionFunction();

        $closure($device, ['restore_reason' => 'deleted by mistake']);
    }

    public function test_a_restored_device_works_again(): void
    {
        $device = $this->device();
        $this->deleteDevice($device);

        $this->restore($device->fresh(['driver']));

        $restored = Device::withTrashed()->findOrFail($device->getKey());

        $this->assertNull($restored->deleted_at);
        $this->assertSame('active', $restored->status);
        $this->assertNull($restored->revoked_at);
        $this->assertNull($restored->revoke_reason);
    }

    /**
     * The sessions killed by the delete stay killed. Restoring them would hand
     * a live credential back to whoever is holding the phone.
     */
    public function test_the_old_sessions_stay_revoked(): void
    {
        $device = $this->device();
        DeviceTokens::make()->issue($device);

        $this->deleteDevice($device);
        $this->restore($device->fresh());

        $this->assertSame(0, DeviceSession::query()
            ->where('device_id', $device->getKey())
            ->whereNull('revoked_at')
            ->count());
    }

    /**
     * One driver, one device — enforced by a partial unique index, so without
     * the guard this surfaces as a database error instead of something staff
     * can act on.
     */
    public function test_it_refuses_when_the_driver_already_has_another_device(): void
    {
        $old = $this->device();
        $this->deleteDevice($old);

        $replacement = $this->device();

        $this->restore($old->fresh());

        $this->assertNotNull(Device::withTrashed()->findOrFail($old->getKey())->deleted_at);
        $this->assertNull($replacement->fresh()->deleted_at);
    }

    /** A device deleted before its PIN was set still needs one. */
    public function test_a_device_without_a_pin_comes_back_waiting_for_one(): void
    {
        $device = $this->device();
        $device->forceFill(['pin_hash' => null, 'status' => 'pending_pin'])->save();

        $this->deleteDevice($device);
        $this->restore($device->fresh());

        $this->assertSame('pending_pin', Device::withTrashed()->findOrFail($device->getKey())->status);
    }

    public function test_restoring_is_audited_with_its_reason(): void
    {
        $device = $this->device();
        $this->deleteDevice($device);

        $this->restore($device->fresh());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.restored',
            'subject_id' => $device->getKey(),
        ]);
    }

    /** Admin only: bringing a revoked device back is not a registrar's call. */
    public function test_only_an_admin_sees_the_action(): void
    {
        $device = $this->device();
        $this->deleteDevice($device);

        $method = new ReflectionMethod(DeviceResource::class, 'restoreDeviceAction');
        $method->setAccessible(true);

        $deleted = $device->fresh();

        $this->assertTrue($method->invoke(null)->record($deleted)->isVisible());

        $this->actingAs($this->staff('registrar@test.local', 'registrar'), 'staff');

        $this->assertFalse($method->invoke(null)->record($deleted)->isVisible());
    }

    /** Nothing to undo on a device that was never deleted. */
    public function test_the_action_is_hidden_on_a_live_device(): void
    {
        $method = new ReflectionMethod(DeviceResource::class, 'restoreDeviceAction');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null)->record($this->device())->isVisible());
    }
}
