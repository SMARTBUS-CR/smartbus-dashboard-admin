<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    private const DEMO_ENVIRONMENTS = ['local', 'development', 'testing'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BusPermissionsSeeder::class,
            TripPermissionsSeeder::class,
            DriverPermissionsSeeder::class,
        ]);

        if (! app()->environment(self::DEMO_ENVIRONMENTS)) {
            $this->call(WorkforcePermissionsSeeder::class);

            return;
        }

        if (! User::query()->where('email', 'admin@company.com')->exists()) {
            throw new RuntimeException(
                'The external MySQL identity admin@company.com is required before seeding development demo data.',
            );
        }

        $this->call([
            CompanySeeder::class,
            SarapiquiRoutesSeeder::class,
            SmartbusDemoRouteSeeder::class,
            WorkforcePermissionsSeeder::class,
            DriverSeeder::class,
            BusSeeder::class,
            TripSeeder::class,
        ]);
    }
}
