<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

describe('Role Colors', function (): void {
    test('persists different generated colors and preserves them when renaming roles', function (): void {
        $company = createCompany();
        $first = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
        $second = Role::create(['name' => 'supervisor', 'guard_name' => 'web', 'company_id' => $company->id]);

        expect($first->fresh()->color)->toMatch('/^#[0-9a-f]{6}$/i')
            ->and($second->fresh()->color)->toMatch('/^#[0-9a-f]{6}$/i')
            ->and($first->color)->not->toBe($second->color);

        $color = $first->color;
        $first->update(['display_name' => 'Traffic Dispatcher']);

        expect($first->fresh()->color)->toBe($color);
    });
});

describe('Role Tenant Permissions', function (): void {
    test('assigning removing and reassigning a role changes its tenant permissions', function () {
        [$company, $foreign] = createTenantPair();
        $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->givePermissionTo(Permission::findOrCreate('View:Role', 'web'));
        $user = User::factory()->create();
        setPermissionsTeamId($company->id);

        $user->assignRole($role);
        expect($user->can('View:Role'))->toBeTrue();
        $user->removeRole($role);
        expect($user->can('View:Role'))->toBeFalse();
        $user->assignRole($role);
        setPermissionsTeamId($foreign->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        expect($user->can('View:Role'))->toBeFalse();
        setPermissionsTeamId($company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        expect($user->can('View:Role'))->toBeTrue();
    });

});
