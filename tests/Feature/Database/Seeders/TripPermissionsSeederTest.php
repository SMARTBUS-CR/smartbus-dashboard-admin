<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Trip;
use Database\Seeders\TripPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

test('creates only the two web permissions needed for trip planning', function (): void {
    $this->seed(TripPermissionsSeeder::class);

    expect(Permission::query()->orderBy('name')->get(['name', 'guard_name'])->toArray())->toBe([
        ['name' => 'Create:Trip', 'guard_name' => 'web'],
        ['name' => 'Update:Trip', 'guard_name' => 'web'],
    ]);
    $this->assertDatabaseCount('trips', 0);
});

test('preserves identities roles grants and context when trip permissions are seeded repeatedly', function (): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::Admin, $company);
    grantShield($actor, ['Create:Trip', 'Unrelated:Permission'], $company);
    $permissionId = Permission::findByName('Create:Trip', 'web')->getKey();
    $roles = Role::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($company->getKey());

    try {
        $grants = $actor->getAllPermissions()->pluck('name')->sort()->values()->all();
        $this->seed(TripPermissionsSeeder::class);
        $ids = Permission::query()->orderBy('name')->pluck('id')->all();
        $this->seed(TripPermissionsSeeder::class);

        expect(Permission::query()->orderBy('name')->pluck('id')->all())->toBe($ids)
            ->and(Permission::findByName('Create:Trip', 'web')->getKey())->toBe($permissionId)
            ->and(Role::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all())->toBe($roles)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        expect($actor->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($grants);
    } finally {
        setPermissionsTeamId($previousTeam);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }
});

test('requires explicit role grants to use seeded planning permissions without Filament', function (): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
    $this->seed(TripPermissionsSeeder::class);
    $trip = (new Trip)->forceFill(['company_id' => $company->getKey(), 'status' => TripStatus::SCHEDULED]);
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($company->getKey());

    try {
        expect(Gate::forUser($actor)->denies('create', [Trip::class, $company]))->toBeTrue()
            ->and(Gate::forUser($actor)->denies('update', [Trip::class, $company, $trip]))->toBeTrue();
        $role = Role::withoutGlobalScopes()->where('company_id', $company->getKey())->where('name', UserRole::Admin->value)->sole();
        $role->givePermissionTo(Permission::findByName('Create:Trip', 'web'));
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        expect(Gate::forUser($actor)->allows('create', [Trip::class, $company]))->toBeTrue()
            ->and(Gate::forUser($actor)->denies('update', [Trip::class, $company, $trip]))->toBeTrue();
        $role->givePermissionTo(Permission::findByName('Update:Trip', 'web'));
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        expect(Gate::forUser($actor)->allows('update', [Trip::class, $company, $trip]))->toBeTrue();
    } finally {
        setPermissionsTeamId($previousTeam);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }
});
