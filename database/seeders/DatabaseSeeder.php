<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BusPermissionsSeeder::class,
            DriverPermissionsSeeder::class,
            CompanySeeder::class,
            SarapiquiRoutesSeeder::class,
            BusSeeder::class,
        ]);
    }
}
