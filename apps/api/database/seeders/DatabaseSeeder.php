<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // `users` comes from Laravel's own scaffolding and nothing in this
        // system reads it — staff are a separate table with their own guard.
        $this->call(StaffSeeder::class);
    }
}
