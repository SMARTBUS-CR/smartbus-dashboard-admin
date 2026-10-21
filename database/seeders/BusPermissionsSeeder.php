<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class BusPermissionsSeeder extends Seeder
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
                foreach (['ViewAny:Bus', 'View:Bus', 'Create:Bus', 'Update:Bus', 'Delete:Bus', 'Restore:Bus'] as $name) {
                    Permission::findOrCreate($name, 'web');
                }
            });
        } finally {
            $registrar->forgetCachedPermissions();
        }
    }
}
