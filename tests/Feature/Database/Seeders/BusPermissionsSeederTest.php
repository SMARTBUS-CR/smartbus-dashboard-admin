<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\BusManagementService;
use Database\Seeders\BusPermissionsSeeder;

describe('Bus Permissions Seeder', function (): void {
    test('makes seeded lifecycle permissions usable without granting unrelated abilities', function (bool $restore): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        $this->seed(BusPermissionsSeeder::class);
        $role = Role::withoutGlobalScopes()->where('company_id', $company->getKey())
            ->where('name', UserRole::Admin->value)->where('guard_name', 'web')->sole();
        $role->givePermissionTo(Permission::findByName($restore ? 'Restore:Bus' : 'Delete:Bus', 'web'));
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $factory = Bus::factory()->for($company);
        $bus = ($restore ? $factory->archived() : $factory)->create();

        $service = app(BusManagementService::class);
        $result = $restore
            ? $service->restore($actor, $company, $bus->getKey())
            : $service->archive($actor, $company, $bus->getKey());

        expect($result->getKey())->toBe($bus->getKey())
            ->and($result->trashed())->toBe(! $restore)
            ->and($role->hasPermissionTo('Update:Bus'))->toBeFalse()
            ->and($role->hasPermissionTo($restore ? 'Delete:Bus' : 'Restore:Bus'))->toBeFalse();
        $this->assertDatabaseHas('buses', ['id' => $bus->getKey(), 'company_id' => $company->getKey()]);
    })->with(['archive' => [false], 'restore' => [true]]);

    test('creates exactly the six web permissions required for bus management', function (): void {
        $this->seed(BusPermissionsSeeder::class);

        expect(Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')
            ->get(['name', 'guard_name'])->toArray())->toBe([
                ['name' => 'Create:Bus', 'guard_name' => 'web'],
                ['name' => 'Delete:Bus', 'guard_name' => 'web'],
                ['name' => 'Restore:Bus', 'guard_name' => 'web'],
                ['name' => 'Update:Bus', 'guard_name' => 'web'],
                ['name' => 'View:Bus', 'guard_name' => 'web'],
                ['name' => 'ViewAny:Bus', 'guard_name' => 'web'],
            ]);
    });

    test('preserves permission identities assignments and team context on repeated execution', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        grantShield($actor, ['Create:Bus', 'Unrelated:Permission'], $company);
        $permissionId = Permission::findByName('Create:Bus', 'web')->getKey();
        $roleIds = Role::withoutGlobalScopes()->pluck('id')->sort()->values()->all();
        $originalTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());

        try {
            $before = $actor->getAllPermissions()->pluck('name')->sort()->values()->all();
            $this->seed(BusPermissionsSeeder::class);
            $ids = Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all();
            $this->seed(BusPermissionsSeeder::class);

            expect(Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all())->toBe($ids)
                ->and(Permission::findByName('Create:Bus', 'web')->getKey())->toBe($permissionId)
                ->and(Role::withoutGlobalScopes()->pluck('id')->sort()->values()->all())->toBe($roleIds)
                ->and(getPermissionsTeamId())->toBe($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            expect($actor->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($before);
        } finally {
            setPermissionsTeamId($originalTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    });

    test('makes a seeded permission usable by an authorized administrator without Filament', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        $this->seed(BusPermissionsSeeder::class);
        $role = Role::withoutGlobalScopes()->where('company_id', $company->getKey())
            ->where('name', UserRole::Admin->value)->where('guard_name', 'web')->sole();
        $role->givePermissionTo(Permission::findByName('Create:Bus', 'web'));
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        $bus = app(BusManagementService::class)->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);

        $this->assertDatabaseHas('buses', ['id' => $bus->getKey(), 'company_id' => $company->getKey()]);
    });
});
