<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

test('applies the explicit execution authorization matrix', function (string $scenario, string $ability, string $permission): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withEligibleDriver()->create();
    $company = $trip->company;
    $actor = match ($scenario) {
        'assigned driver', 'driver without membership', 'archived driver identity', 'driver without role' => $trip->driver->user,
        'super admin', 'foreign record' => createUserWithRole(UserRole::SuperAdmin),
        default => createUserWithRole($scenario === 'passenger' ? UserRole::Passenger : ($scenario === 'other driver' ? UserRole::Driver : UserRole::Admin), $company),
    };
    if (! in_array($scenario, ['assigned driver', 'driver without membership', 'archived driver identity', 'driver without role', 'super admin', 'foreign record', 'admin without membership'], true)) {
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    }
    if (in_array($scenario, ['admin', 'admin without membership', 'passenger', 'other driver'], true)) {
        grantShield($actor, [$permission], $company);
    }
    if ($scenario === 'driver without membership') {
        CompanyUser::query()->where('user_id', $actor->id)->delete();
    } elseif ($scenario === 'archived driver identity') {
        $actor->delete();
    } elseif ($scenario === 'driver without role') {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->id)->delete();
    }
    $selected = $scenario === 'foreign record' ? createCompany() : $company;
    $previous = getPermissionsTeamId();
    setPermissionsTeamId($selected->id);

    try {
        $allowed = in_array($scenario, ['admin', 'super admin'], true)
            || ($scenario === 'assigned driver' && $ability !== 'cancel');
        expect(Gate::forUser($actor)->allows($ability, [Trip::class, $selected, $trip]))->toBe($allowed);
    } finally {
        setPermissionsTeamId($previous);
    }
    Http::assertNothingSent();
})->with(['admin', 'super admin', 'assigned driver', 'other driver', 'passenger', 'admin without permission',
    'admin without membership', 'driver without membership', 'archived driver identity', 'driver without role', 'foreign record'])
    ->with(['start' => ['start', 'Start:Trip'], 'complete' => ['complete', 'Complete:Trip'], 'cancel' => ['cancel', 'Cancel:Trip']]);

test('reuses dashboard access and explicit permissions for custom company administration roles', function (string $ability, string $permission): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withEligibleDriver()->create();
    $actor = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    $previous = getPermissionsTeamId();
    setPermissionsTeamId($trip->company_id);

    try {
        $role = Role::findOrCreate('operations', 'web');
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        $actor->assignRole($role);

        expect(Gate::forUser($actor)->allows($ability, [Trip::class, $trip->company, $trip]))->toBeTrue();
    } finally {
        setPermissionsTeamId($previous);
    }
})->with(['start' => ['start', 'Start:Trip'], 'complete' => ['complete', 'Complete:Trip'], 'cancel' => ['cancel', 'Cancel:Trip']]);
