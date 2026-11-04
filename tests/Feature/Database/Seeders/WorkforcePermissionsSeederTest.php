<?php

use App\Enums\CompanyStatus;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\WorkforcePermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

describe('Workforce Permissions Seeder', function (): void {
    test('adds grants only to company web admins and restores the previous team', function (): void {
        $company = createCompany();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);
        $admin = Role::query()->where('name', 'admin')->where('company_id', $company->id)->sole();
        $admin->givePermissionTo(Permission::findOrCreate('View:Bus', 'web'));
        $archived = createCompany();
        $archived->delete();
        $inactive = createCompany(['status' => CompanyStatus::INACTIVE]);
        $excluded = collect([
            ['driver', 'web', $company->id], ['passenger', 'web', $company->id],
            ['admin', 'api', $company->id], ['admin', 'web', null], ['admin', 'web', $archived->id],
            ['admin', 'web', $inactive->id],
        ])->map(fn (array $data): Role => Role::query()->firstOrCreate(['name' => $data[0], 'guard_name' => $data[1], 'company_id' => $data[2]]));

        $this->seed(WorkforcePermissionsSeeder::class);
        $before = DB::connection('mysql')->table('role_has_permissions')->orderBy('permission_id')->get()->toArray();
        $this->seed(WorkforcePermissionsSeeder::class);

        expect($admin->permissions()->count())->toBe(14)
            ->and($admin->hasPermissionTo('ViewSalary:Driver'))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and(DB::connection('mysql')->table('role_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($before);
        foreach ($excluded as $role) {
            expect($role->permissions()->count())->toBe(0);
        }
        $this->assertDatabaseCount('permissions', 14, 'mysql');
    });

    test('rolls back partial grants and restores team context on failure', function (): void {
        createCompany();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);
        $connection = DB::connection('mysql');
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `role_has_permissions`')) {
                throw new RuntimeException('Injected permission assignment failure');
            }
        });

        try {
            expect(fn () => $this->seed(WorkforcePermissionsSeeder::class))
                ->toThrow(RuntimeException::class, 'Injected permission assignment failure');
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        expect(getPermissionsTeamId())->toBe($previousTeam)
            ->and($connection->table('role_has_permissions')->count())->toBe(0);
        $this->seed(WorkforcePermissionsSeeder::class);
        expect($connection->table('role_has_permissions')->count())->toBe(26);
    });
});
