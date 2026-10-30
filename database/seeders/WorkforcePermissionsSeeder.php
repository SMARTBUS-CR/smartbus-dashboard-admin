<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class WorkforcePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        $registrar = app(PermissionRegistrar::class);

        try {
            $this->call(DriverPermissionsSeeder::class);
            $companyIds = Company::query()->where('status', CompanyStatus::ACTIVE)->pluck('id');
            $permissions = Permission::query()->where('guard_name', 'web')
                ->whereIn('name', [
                    'ViewAny:Driver', 'View:Driver', 'Create:Driver', 'Update:Driver', 'Delete:Driver',
                    'Restore:Driver', 'ViewSalary:Driver', 'ViewAttendance:Driver',
                    'ViewAny:DriverSchedule', 'View:DriverSchedule', 'Create:DriverSchedule',
                    'Update:DriverSchedule', 'Delete:DriverSchedule',
                ])->get();

            DB::connection('mysql')->transaction(function () use ($companyIds, $permissions): void {
                foreach (Role::withoutGlobalScopes()->whereIn('company_id', $companyIds)->where('name', UserRole::Admin->value)
                    ->where('guard_name', 'web')->orderBy('id')->get() as $role) {
                    setPermissionsTeamId($role->company_id);
                    $role->givePermissionTo($permissions);
                }
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
