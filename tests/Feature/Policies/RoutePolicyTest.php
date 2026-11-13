<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Route;
use Illuminate\Support\Facades\Gate;

describe('Route Policy Archival Permissions', function (): void {
    test('rejects an operation when its permission is missing', function (string $ability, bool $archived): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        if ($archived) {
            $route->delete();
        }

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->denies($ability, $route))
            ->toBeTrue();
    })->with([
        'archival' => ['delete', false],
        'restoration' => ['restore', true],
    ]);

    test('allows an operation with the corresponding company permission', function (string $ability, string $permission, bool $archived): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        if ($archived) {
            $route->delete();
        }

        $actor = createUserWithRole('route-manager', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route', $permission],
            $company,
        );

        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->allows($ability, $route))
            ->toBeTrue();
    })->with([
        'archival' => ['delete', 'Delete:Route', false],
        'restoration' => ['restore', 'Restore:Route', true],
    ]);

    test('rejects an operation against another company route', function (string $ability, string $permission, bool $archived): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();

        if ($archived) {
            $foreignRoute->delete();
        }

        $actor = createUserWithRole('route-manager', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route', $permission],
            $company,
        );

        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->denies($ability, $foreignRoute))
            ->toBeTrue();
    })->with([
        'foreign archival' => ['delete', 'Delete:Route', false],
        'foreign restoration' => ['restore', 'Restore:Route', true],
    ]);
});

describe('Route Policy Permanent Deletion', function (): void {
    test('rejects permanent deletion even for a system admin', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->denies('forceDelete', $route))
            ->toBeTrue()
            ->and(Gate::forUser($actor)->denies('forceDeleteAny', Route::class))
            ->toBeTrue();
    });
});
