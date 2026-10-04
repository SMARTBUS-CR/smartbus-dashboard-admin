<?php

use App\Enums\UserRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

function companyRole(Company $company, string $name = 'dispatcher', string $displayName = 'Dispatcher'): Role
{
    return Role::create(['name' => $name, 'display_name' => $displayName, 'guard_name' => 'web', 'company_id' => $company->id]);
}

describe('Role Resource', function (): void {
    test('creates a role with permissions in the active company and ignores a forged tenant and guard', function () {
        [$company, $foreign] = createTenantPair();
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Create:Role', 'View:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(CreateRole::class)->fillForm([
            'display_name' => 'Traffic Dispatcher', 'name' => 'forged-name', 'company_id' => $foreign->id,
            RoleResource::class => ['View:Role'],
        ])->set('data.guard_name', 'api')->call('create')->assertHasNoFormErrors();

        $role = Role::withoutGlobalScopes()->where('name', 'traffic-dispatcher')->sole();
        expect($role->company_id)->toBe($company->id)
            ->and($role->guard_name)->toBe('web')
            ->and($role->permissions()->pluck('name')->all())->toBe(['View:Role']);
        $this->assertDatabaseMissing(Role::class, ['name' => 'forged-name']);
    });

    test('edits a role and synchronizes rather than accumulates its permissions', function () {
        $company = createCompany();
        $role = companyRole($company);
        $role->givePermissionTo(Permission::findOrCreate('View:Role', 'web'));
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Update:Role', 'Delete:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(EditRole::class, ['record' => $role->id])
            ->assertSchemaStateSet(['display_name' => 'Dispatcher', 'name' => 'dispatcher'])
            ->fillForm(['display_name' => 'Traffic Supervisor', RoleResource::class => ['Delete:Role']])
            ->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas(Role::class, ['id' => $role->id, 'name' => 'traffic-supervisor', 'display_name' => 'Traffic Supervisor', 'company_id' => $company->id]);
        expect($role->permissions()->pluck('name')->all())->toBe(['Delete:Role']);
    });

    test('displays the role and its assigned permissions', function () {
        $company = createCompany();
        $role = companyRole($company);
        $role->givePermissionTo(Permission::findOrCreate('View:Role', 'web'));
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'View:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(ViewRole::class, ['record' => $role->id])
            ->assertSchemaStateSet(['display_name' => 'Dispatcher', 'name' => 'dispatcher', RoleResource::class => ['View:Role']]);
    });

    test('validates the role display name', function (mixed $name, string $rule) {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(CreateRole::class)->fillForm(['display_name' => $name])->call('create')
            ->assertHasFormErrors(['display_name' => $rule]);

        $this->assertDatabaseCount(Role::class, 3);
    })->with([[null, 'required'], [str_repeat('a', 256), 'max']]);

    test('rejects duplicate names within the active company', function () {
        $company = createCompany();
        companyRole($company);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(CreateRole::class)->fillForm(['display_name' => 'Dispatcher', 'name' => 'forged-unique-name'])
            ->call('create')->assertHasFormErrors(['name' => 'unique']);

        expect(Role::where('name', 'dispatcher')->count())->toBe(1);
    });

    test('allows a name used by another company', function () {
        [$company, $foreign] = createTenantPair();
        companyRole($foreign);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(CreateRole::class)->fillForm(['display_name' => 'Dispatcher'])->call('create')->assertHasNoFormErrors();

        expect(Role::withoutGlobalScopes()->where('name', 'dispatcher')->pluck('company_id')->sort()->values()->all())
            ->toBe(collect([$company->id, $foreign->id])->sort()->values()->all());
    });

    test('does not grant an administrator permissions they do not own when creating a role', function () {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Create:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(CreateRole::class)->fillForm(['display_name' => 'Escalated Role', RoleResource::class => ['Delete:Role']])
            ->call('create')->assertForbidden();

        $this->assertDatabaseMissing(Role::class, ['name' => 'escalated-role']);
        $this->assertDatabaseMissing(Permission::class, ['name' => 'Delete:Role']);
    });

    test('rejects an unauthorized permission before updating any part of the role', function () {
        $company = createCompany();
        $role = companyRole($company);
        $role->givePermissionTo(Permission::findOrCreate('View:Role', 'web'));
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Update:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(EditRole::class, ['record' => $role->id])
            ->fillForm(['display_name' => 'Changed Name', RoleResource::class => ['View:Role', 'Delete:Role']])
            ->call('save')->assertForbidden();

        $this->assertDatabaseHas(Role::class, ['id' => $role->id, 'display_name' => 'Dispatcher', 'name' => 'dispatcher']);
        expect($role->permissions()->pluck('name')->all())->toBe(['View:Role']);
    });

    test('allows retaining existing permissions when the editor does not possess them', function () {
        // Retaining an existing permission is not a new grant.
        $company = createCompany();
        $role = companyRole($company);
        $role->givePermissionTo(Permission::findOrCreate('Delete:Role', 'web'));
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Update:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(EditRole::class, ['record' => $role->id])->fillForm(['display_name' => 'Renamed Dispatcher'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas(Role::class, ['id' => $role->id, 'display_name' => 'Renamed Dispatcher']);
        expect($role->permissions()->pluck('name')->all())->toBe(['Delete:Role']);
    });

    test('prevents administrators from creating reserved role identifiers', function (string $name) {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Create:Role'], $company);
        actingAsInCompany($admin, $company);
        $before = Role::count();

        Livewire::test(CreateRole::class)->fillForm(['display_name' => $name])->call('create')->assertHasFormErrors(['name']);

        expect(Role::count())->toBe($before);
    })->with(['Super Admin', 'Admin', 'Driver', 'Passenger']);

    test('prevents renaming a custom role into super-admin', function () {
        $company = createCompany();
        $role = companyRole($company);
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Update:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(EditRole::class, ['record' => $role->id])->fillForm(['display_name' => 'Super Admin'])
            ->call('save')->assertHasFormErrors(['name' => 'not_in']);

        $this->assertDatabaseHas(Role::class, ['id' => $role->id, 'name' => 'dispatcher']);
    });

    test('preserves protected identifiers when their display name or posted identifier changes', function () {
        $company = createCompany();
        $role = Role::where('company_id', $company->id)->where('name', 'admin')->sole();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(EditRole::class, ['record' => $role->id])->fillForm(['display_name' => 'Company Manager'])
            ->set('data.name', 'not-protected')->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas(Role::class, ['id' => $role->id, 'name' => 'admin', 'display_name' => 'Company Manager']);
    });

    test('super-admin can grant permissions and edit roles after switching to any company', function () {
        [$company, $other] = createTenantPair();
        $first = companyRole($company);
        $second = companyRole($other);
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($superAdmin, $company);

        Livewire::test(ListRoles::class)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        actingAsInCompany($superAdmin, $other);
        Livewire::test(ListRoles::class)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        Livewire::test(EditRole::class, ['record' => $second->id])->fillForm([RoleResource::class => ['Delete:Role']])
            ->call('save')->assertHasNoFormErrors();

        expect($second->permissions()->pluck('name')->all())->toBe(['Delete:Role']);
    });

    test('deletes an unused custom role through the table action', function () {
        $company = createCompany();
        $role = companyRole($company);
        $other = companyRole($company, 'supervisor', 'Supervisor');
        $admin = createUserWithRole(UserRole::Admin, $company);
        grantShield($admin, ['ViewAny:Role', 'Delete:Role'], $company);
        actingAsInCompany($admin, $company);

        Livewire::test(ListRoles::class)->callAction(TestAction::make('delete')->table($role));

        $this->assertModelMissing($role);
        $this->assertModelExists($other);
    });

    test('bulk deletion preserves protected assigned foreign and unselected roles', function (string $actorRole) {
        [$company, $foreign] = createTenantPair();
        $unused = companyRole($company);
        $assigned = companyRole($company, 'assigned', 'Assigned');
        $unselected = companyRole($company, 'unselected', 'Unselected');
        $foreignRole = companyRole($foreign);
        $protected = Role::where('company_id', $company->id)->whereIn('name', ['admin', 'driver'])->get();
        $assignee = User::factory()->create();
        setPermissionsTeamId($company->id);
        $assignee->assignRole($assigned);
        $actor = createUserWithRole($actorRole, $actorRole === 'admin' ? $company : null);
        grantShield($actor, ['ViewAny:Role', 'DeleteAny:Role', 'Delete:Role'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListRoles::class)->selectTableRecords([...$protected->modelKeys(), $assigned->id, $unused->id, $foreignRole->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertModelMissing($unused);
        foreach ([...$protected, $assigned, $unselected, $foreignRole] as $role) {
            $this->assertModelExists($role);
        }
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $assigned->id, 'model_uuid' => $assignee->id], 'mysql');
    })->with(['admin', 'super-admin']);

    test('blocks individual deletion of assigned roles including for super-admin', function () {
        $company = createCompany();
        $role = companyRole($company);
        setPermissionsTeamId($company->id);
        User::factory()->create()->assignRole($role);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(ListRoles::class)->assertActionHidden(TestAction::make('delete')->table($role));
        expect(Filament::auth()->user()->can('delete', $role))->toBeFalse();
        $this->assertModelExists($role);
    });

    test('searches role display names and system identifiers without exposing another company', function (string $search) {
        [$company, $foreign] = createTenantPair();
        $matching = companyRole($company, 'operations', 'Dispatch Team');
        $other = companyRole($company, 'finance', 'Finance');
        $foreignRole = companyRole($foreign, 'operations', 'Dispatch Team');
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(ListRoles::class)->searchTable($search)->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other, $foreignRole]);
    })->with(['Dispatch', 'operations']);

    test('refuses direct foreign role URLs even when the administrator belongs to both companies', function () {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->withSession(['external_auth_token' => 'test-token']);

        [$company, $foreign] = createTenantPair();
        $role = companyRole($foreign);
        $admin = createUserWithRole(UserRole::Admin, $company);
        $admin->companies()->syncWithoutDetaching([$company->id, $foreign->id]);
        grantShield($admin, ['ViewAny:Role', 'View:Role', 'Update:Role', 'Delete:Role'], $company);
        actingAsInCompany($admin, $company);

        $this->get(RoleResource::getUrl('view', ['record' => $role], tenant: $company))->assertNotFound();
        $this->get(RoleResource::getUrl('edit', ['record' => $role], tenant: $company))->assertNotFound();
        $this->assertModelExists($role);
    });

    test('denies access to a tenant to which the administrator does not belong', function () {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->withSession(['external_auth_token' => 'test-token']);

        [$company, $foreign] = createTenantPair();
        $admin = createUserWithRole(UserRole::Admin, $company);
        $admin->companies()->syncWithoutDetaching([$company->id]);
        grantShield($admin, ['ViewAny:Role'], $company);
        $this->actingAs($admin);

        $this->get(RoleResource::getUrl('index', tenant: $foreign))->assertNotFound();
    });

    test('denies the roles page without the Shield permission', function () {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->withSession(['external_auth_token' => 'test-token']);

        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);
        $admin->companies()->syncWithoutDetaching([$company->id]);
        $this->actingAs($admin);

        $this->get(RoleResource::getUrl('index', tenant: $company))->assertForbidden();
    });

    test('deletes an unused custom role from its edit page', function () {
        $company = createCompany();
        $role = companyRole($company);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(EditRole::class, ['record' => $role->id])->callAction('delete');

        $this->assertModelMissing($role);
    });

    test('rejects non-administrative panel roles even if they have Shield permissions', function (string $role) {
        $company = createCompany();
        $user = createUserWithRole($role, $company);
        $user->companies()->syncWithoutDetaching([$company->id]);
        grantShield($user, ['ViewAny:Role', 'View:Role'], $company);
        $this->actingAs($user);

        $this->get(RoleResource::getUrl('index', tenant: $company))->assertForbidden();
    })->with(['driver', 'passenger']);
});
