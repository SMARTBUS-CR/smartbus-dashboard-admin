<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Trip;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

test('authorizes trip history with current identity membership and the specific read permission', function (string $scenario, string $ability, string $permission): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withEligibleDriver()->create();
    $company = $trip->company;
    $actor = match ($scenario) {
        'assigned driver', 'revoked driver role' => $trip->driver->user,
        'super admin', 'foreign super admin', 'deleted super admin' => createUserWithRole(UserRole::SuperAdmin),
        default => createUserWithRole(match ($scenario) {
            'passenger' => UserRole::Passenger,
            'other driver', 'foreign driver' => UserRole::Driver,
            'custom admin' => 'operations',
            default => UserRole::Admin,
        }, $company),
    };

    if (! in_array($scenario, ['assigned driver', 'revoked driver role', 'super admin', 'foreign super admin', 'deleted super admin'], true)) {
        CompanyUser::factory()->create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        if ($scenario !== 'missing permission') {
            grantShield($actor, [$scenario === 'wrong permission' ? ($ability === 'viewAny' ? 'View:Trip' : 'ViewAny:Trip') : $permission], $company);
        }
    }

    if (in_array($scenario, ['deleted identity', 'deleted super admin'], true)) {
        $actor->delete();
    } elseif ($scenario === 'revoked membership') {
        CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $actor->getKey())->delete();
    } elseif (in_array($scenario, ['revoked driver role', 'revoked admin roles'], true)) {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->getKey())->where('company_id', $company->getKey())->delete();
    } elseif ($scenario === 'inactive company') {
        $company->update(['status' => CompanyStatus::INACTIVE]);
    } elseif ($scenario === 'archived company') {
        $company->delete();
    }

    $selectedCompany = in_array($scenario, ['foreign super admin', 'foreign admin', 'foreign driver'], true) ? createCompany() : $company;
    $previousTeam = getPermissionsTeamId();
    try {
        setPermissionsTeamId($selectedCompany->getKey());
        $arguments = $ability === 'view' ? [Trip::class, $selectedCompany, $trip] : [Trip::class, $selectedCompany];
        $allowed = in_array($scenario, ['admin', 'custom admin', 'assigned driver', 'super admin', 'inactive company', 'archived company'], true)
            || ($ability === 'viewAny' && in_array($scenario, ['other driver', 'foreign super admin'], true));

        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBe($allowed);
    } finally {
        setPermissionsTeamId($previousTeam);
    }
    Http::assertNothingSent();
})->with([
    'admin', 'custom admin', 'assigned driver', 'other driver', 'super admin', 'foreign super admin',
    'passenger', 'missing permission', 'wrong permission', 'deleted identity', 'deleted super admin',
    'revoked membership', 'revoked driver role', 'revoked admin roles', 'inactive company', 'archived company',
    'foreign admin', 'foreign driver',
])->with(['list' => ['viewAny', 'ViewAny:Trip'], 'detail' => ['view', 'View:Trip']]);
