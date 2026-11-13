<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class TripPermissionsSeeder extends Seeder
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
                    'Create:Trip', 'Update:Trip', 'Start:Trip', 'Complete:Trip', 'Cancel:Trip', 'ViewAny:Trip', 'View:Trip',
                    'ViewAny:TripAssignment', 'View:TripAssignment', 'Create:TripAssignment',
                    'Update:TripAssignment', 'Delete:TripAssignment', 'Restore:TripAssignment',
                ] as $name) {
                    Permission::findOrCreate($name, 'web');
                }
            });
        } finally {
            $registrar->forgetCachedPermissions();
        }
    }
}
