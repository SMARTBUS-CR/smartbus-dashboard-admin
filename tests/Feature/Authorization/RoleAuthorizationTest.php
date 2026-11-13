<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

describe('Role Authorization', function (): void {
    test('enforces each Shield ability through the application gate', function (string $ability, string $permission, bool $recordAbility) {
        $company = createCompany();
        $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
        $admin = createUserWithRole(UserRole::Admin, $company);
        $this->actingAs($admin);
        Filament::setTenant($company, isQuiet: true);
        setPermissionsTeamId($company->id);
        $target = $recordAbility ? $role : Role::class;

        expect(Gate::forUser($admin)->allows($ability, $target))->toBeFalse();
        grantShield($admin, [$permission], $company);
        expect(Gate::forUser($admin)->allows($ability, $target))->toBeTrue();
    })->with([
        ['viewAny', 'ViewAny:Role', false], ['view', 'View:Role', true],
        ['create', 'Create:Role', false], ['update', 'Update:Role', true],
        ['delete', 'Delete:Role', true], ['deleteAny', 'DeleteAny:Role', false],
    ]);

    test('denies foreign role read update and deletion despite matching permissions', function (string $ability) {
        [$company, $foreign] = createTenantPair();
        $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $foreign->id]);
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['View:Role', 'Update:Role', 'Delete:Role'], $company);
        actingAsInCompany($admin, $company);

        expect(Gate::forUser($admin)->allows($ability, $role))->toBeFalse();
    })->with(['view', 'update', 'delete']);

    test('the super-admin bypass never permits deleting a protected role', function (string $protected, string $ability) {
        // Super-admin retains global administration, but protected roles are undeletable.
        $company = createCompany();
        setPermissionsTeamId($company->id);
        $role = Role::findOrCreate($protected, 'web');
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($superAdmin, $company);

        expect(Gate::forUser($superAdmin)->allows($ability, $role))->toBeFalse();
        $this->assertModelExists($role);
    })->with(['super-admin', 'admin', 'driver', 'passenger'])->with(['delete', 'forceDelete']);

    test('blocks deletion when the only assignee is soft deleted', function () {
        $company = createCompany();
        $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
        setPermissionsTeamId($company->id);
        $assignee = User::factory()->create();
        $assignee->assignRole($role);
        $assignee->delete();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($superAdmin, $company);

        expect(Gate::forUser($superAdmin)->allows('delete', $role))->toBeFalse();
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $role->id, 'model_uuid' => $assignee->id], 'mysql');
    });

});
