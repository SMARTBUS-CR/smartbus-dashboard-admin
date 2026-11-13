<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Trip;
use App\Services\TripReadService;
use Database\Seeders\TripPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

test('creates the web permissions needed for trip planning execution and history', function (): void {
    $this->seed(TripPermissionsSeeder::class);

    expect(Permission::query()->orderBy('name')->get(['name', 'guard_name'])->toArray())->toBe([
        ['name' => 'Cancel:Trip', 'guard_name' => 'web'],
        ['name' => 'Complete:Trip', 'guard_name' => 'web'],
        ['name' => 'Create:Trip', 'guard_name' => 'web'],
        ['name' => 'Create:TripAssignment', 'guard_name' => 'web'],
        ['name' => 'Delete:TripAssignment', 'guard_name' => 'web'],
        ['name' => 'Restore:TripAssignment', 'guard_name' => 'web'],
        ['name' => 'Start:Trip', 'guard_name' => 'web'],
        ['name' => 'Update:Trip', 'guard_name' => 'web'],
        ['name' => 'Update:TripAssignment', 'guard_name' => 'web'],
        ['name' => 'View:Trip', 'guard_name' => 'web'],
        ['name' => 'View:TripAssignment', 'guard_name' => 'web'],
        ['name' => 'ViewAny:Trip', 'guard_name' => 'web'],
        ['name' => 'ViewAny:TripAssignment', 'guard_name' => 'web'],
    ]);
    $this->assertDatabaseCount('trips', 0);
});

test('preserves identities roles grants and context when trip permissions are seeded repeatedly', function (): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::Admin, $company);
    grantShield($actor, ['Create:Trip', 'View:Trip', 'ViewAny:Trip', 'Unrelated:Permission'], $company);
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

test('uses independently granted seeded history permissions without Filament', function (string $permission, string $operation): void {
    $trip = Trip::factory()->create();
    $company = $trip->company;
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    $this->seed(TripPermissionsSeeder::class);
    $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', UserRole::Admin->value)->sole();
    $service = app(TripReadService::class);
    $list = fn () => $service->listFor($actor, $company, '2026-10-26', '2026-10-26')->sole();
    $detail = fn () => $service->findFor($actor, $company, $trip->id);
    $read = $operation === 'list' ? $list : $detail;
    $otherRead = $operation === 'list' ? $detail : $list;

    expect($read)->toThrow(AuthorizationException::class);
    $role->givePermissionTo(Permission::findByName($permission, 'web'));
    expect($read()['id'])->toBe($trip->id)
        ->and($otherRead)->toThrow(AuthorizationException::class);

    $this->seed(TripPermissionsSeeder::class);
    expect($read()['id'])->toBe($trip->id);
    $role->revokePermissionTo($permission);
    expect($read)->toThrow(AuthorizationException::class);
})->with(['list' => ['ViewAny:Trip', 'list'], 'detail' => ['View:Trip', 'detail']]);

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
