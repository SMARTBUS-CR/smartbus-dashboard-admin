<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Trip;
use Illuminate\Support\Facades\Gate;

test('applies explicit company planning authorization', function (string $scenario, bool $allowed, string $ability, string $permission): void {
    [$company, $otherCompany] = createTenantPair();
    $owner = $scenario === 'foreign admin' ? $otherCompany : $company;
    $role = match ($scenario) {
        'super admin' => UserRole::SuperAdmin,
        'driver' => UserRole::Driver,
        'passenger' => UserRole::Passenger,
        default => UserRole::Admin,
    };
    $actor = createUserWithRole($role, $role === UserRole::SuperAdmin ? null : $owner);
    if ($role !== UserRole::SuperAdmin) {
        if ($scenario !== 'missing membership') {
            $membership = CompanyUser::create(['company_id' => $owner->getKey(), 'user_id' => $actor->getKey()]);
        }
        if ($scenario !== 'missing permission') {
            grantShield($actor, [$permission], $owner);
        }
        if ($scenario === 'archived membership') {
            $membership->delete();
        }
        if ($scenario === 'archived actor') {
            $actor->delete();
        }
    }
    $trip = (new Trip)->forceFill(['company_id' => $company->getKey(), 'status' => TripStatus::SCHEDULED]);
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($company->getKey());
    $actor->unsetRelation('roles')->unsetRelation('permissions');

    try {
        $arguments = $ability === 'create' ? [Trip::class, $company] : [Trip::class, $company, $trip];
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBe($allowed);
    } finally {
        setPermissionsTeamId($previousTeam);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }
})->with([
    ['super admin', true], ['own admin', true], ['missing permission', false],
    ['missing membership', false], ['foreign admin', false], ['driver', false], ['passenger', false],
    ['archived membership', false], ['archived actor', false],
])->with(['create' => ['create', 'Create:Trip'], 'replan' => ['update', 'Update:Trip']]);

test('denies deletion and replanning of started or terminal trips even for super admins', function (string $ability, TripStatus $status): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $trip = (new Trip)->forceFill(['company_id' => $company->getKey(), 'status' => $status]);

    expect(Gate::forUser($actor)->denies($ability, [Trip::class, $company, $trip]))->toBeTrue();
    if ($ability !== 'update') {
        expect(Gate::forUser($actor)->denies($ability, $trip))->toBeTrue()
            ->and(Gate::forUser($actor)->denies($ability, Trip::class))->toBeTrue();
    }
})->with(['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'update'])
    ->with([TripStatus::IN_PROGRESS, TripStatus::COMPLETED, TripStatus::CANCELLED]);

test('denies replanning another company record inside the selected company context', function (UserRole $role): void {
    [$company, $otherCompany] = createTenantPair();
    $actor = createUserWithRole($role, $role === UserRole::Admin ? $company : null);
    if ($role === UserRole::Admin) {
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['Update:Trip'], $company);
    }
    $trip = (new Trip)->forceFill(['company_id' => $otherCompany->getKey(), 'status' => TripStatus::SCHEDULED]);
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($company->getKey());
    $actor->unsetRelation('roles')->unsetRelation('permissions');

    try {
        expect(Gate::forUser($actor)->denies('update', [Trip::class, $company, $trip]))->toBeTrue();
    } finally {
        setPermissionsTeamId($previousTeam);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }
})->with([UserRole::Admin, UserRole::SuperAdmin]);
