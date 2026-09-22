<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One admin account so a fresh checkout can sign in to the portal.
 *
 * There is no self-registration and no other way to create the first member of
 * staff, so without this a clone boots into a login screen nobody can pass.
 *
 * The credentials are fixed and published in the README on purpose: this exists
 * to let someone try the portal, not to protect anything. That is also why it
 * refuses to run outside local.
 */
class StaffSeeder extends Seeder
{
    public const EMAIL = 'admin@driver.test';

    public const PASSWORD = 'dev-password';

    public const DATA_ACCESS_PIN = '123456';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'StaffSeeder creates an account with a published password and must never run outside local.'
            );
        }

        $staff = Staff::firstOrNew(['email' => self::EMAIL]);

        $staff->fill([
            'role' => 'admin',
            'can_view_pii' => true,
            'can_download_documents' => true,
        ]);

        // Assigned explicitly - the model keeps secrets out of $fillable so a
        // request array can never set them.
        $staff->password_hash = Hash::make(self::PASSWORD);
        $staff->data_access_pin_hash = Hash::make(self::DATA_ACCESS_PIN);
        $staff->save();

        $this->command->info('staff: '.self::EMAIL.' / '.self::PASSWORD.'  (step-up PIN '.self::DATA_ACCESS_PIN.')');
        $this->command->warn('Development credentials. Change them before this database is used anywhere else.');
    }
}
