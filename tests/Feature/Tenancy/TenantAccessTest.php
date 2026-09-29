<?php

use App\Enums\UserRole;
use App\Http\Middleware\SyncSpatieTeam;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

describe('tenancy and team scope', function () {
    test('switching A to B to A discards previously loaded role permissions', function () {
        [$companyA, $companyB] = createTenantPair();
        $admin = createUserWithRole(UserRole::Admin, $companyA);
        $admin->companies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
        grantShield($admin, ['View:Role'], $companyA);
        $this->actingAs($admin);
        setPermissionsTeamId($companyA->id);
        expect($admin->can('View:Role'))->toBeTrue();

        Filament::setTenant($companyB);
        (new SyncSpatieTeam)->handle(Request::create('/admin'), fn () => response('ok'));
        expect($admin->can('View:Role'))->toBeFalse();
        Filament::setTenant($companyA);
        (new SyncSpatieTeam)->handle(Request::create('/admin'), fn () => response('ok'));
        expect($admin->can('View:Role'))->toBeTrue();
    });

    test('super-admin accesses panel and every tenant', function () {
        [$companyA, $companyB] = createTenantPair();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $panel = Filament::getPanel('admin');

        expect($superAdmin->canAccessPanel($panel))->toBeTrue()
            ->and($superAdmin->getTenants($panel)->modelKeys())->toContain($companyA->id, $companyB->id);
    });

    test('company admin without companies cannot access the panel', function () {
        $admin = createUserWithRole(UserRole::Admin);
        $panel = Filament::getPanel('admin');

        // Role alone is not enough: membership is required.
        expect($admin->canAccessPanel($panel))->toBeFalse()
            ->and($admin->getTenants($panel))->toBeEmpty();
    });

    test('driver and passenger never access the panel or tenants', function (string $role) {
        $company = createCompany();
        $user = createUserWithRole($role);
        $user->companies()->syncWithoutDetaching([$company->id]);
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeFalse()
            ->and($user->getTenants($panel))->toBeEmpty();
    })->with([
        'driver' => UserRole::Driver->value,
        'passenger' => UserRole::Passenger->value,
    ]);

    test('syncs the spatie team id from the current filament tenant', function () {
        $company = createCompany();
        $this->actingAs(createUserWithRole(UserRole::Admin, $company));
        $previousTenant = Filament::getTenant();

        try {
            Filament::setTenant($company);

            (new SyncSpatieTeam)->handle(Request::create('/admin'), fn () => response('ok'));

            expect(app(PermissionRegistrar::class)->getPermissionsTeamId())
                ->toBe($company->getKey());
        } finally {
            Filament::setTenant($previousTenant);
            setPermissionsTeamId(null);
        }
    });

    test('clears the team id when no tenant is active', function () {
        $previousTenant = Filament::getTenant();

        try {
            Filament::setTenant(null);

            (new SyncSpatieTeam)->handle(Request::create('/admin'), fn () => response('ok'));

            expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
        } finally {
            Filament::setTenant($previousTenant);
            setPermissionsTeamId(null);
        }
    });
});
