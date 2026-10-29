<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class DriverPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        try {
            DB::connection('mysql')->transaction(function (): void {
                foreach ([
                    'ViewAny:Driver', 'View:Driver', 'Create:Driver', 'Update:Driver', 'Delete:Driver',
                    'Restore:Driver', 'ViewSalary:Driver', 'ViewAttendance:Driver',
                    'ViewAny:DriverSchedule', 'View:DriverSchedule', 'Create:DriverSchedule',
                    'Update:DriverSchedule', 'Delete:DriverSchedule',
                ] as $name) {
                    Permission::findOrCreate($name, 'web');
                }
            });
        } finally {
            $registrar->forgetCachedPermissions();
        }
    }
}
