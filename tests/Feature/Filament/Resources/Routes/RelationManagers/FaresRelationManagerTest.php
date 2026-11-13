<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RelationManagers\FaresRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RouteFare;
use Livewire\Livewire;

describe('Route Fare Relation Manager', function (): void {
    test('lists only the fares belonging to the selected route', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $fare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
        ]);

        $otherFare = RouteFare::factory()->create([
            'route_id' => $otherRoute->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => ViewRoute::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$fare])
            ->assertCanNotSeeTableRecords([$otherFare]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role with view permission' => ['route-viewer'],
    ]);

    test('rejects a route belonging to another company even for a system admin', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();
        $foreignRoute = Route::factory()->for($otherCompany)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $foreignRoute,
            'pageClass' => ViewRoute::class,
        ])
            ->assertForbidden();
    });

    test('rejects access without permission to view the route', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['ViewAny:Route'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => ViewRoute::class,
        ])
            ->assertForbidden();
    });

    test('displays fare validity dates using the global table format', function (): void {
        app()->setLocale('en');

        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $fare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-12-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => ViewRoute::class,
        ])
            ->assertTableColumnFormattedStateSet(
                'valid_from',
                '12 Oct, 2026',
                $fare,
            )
            ->assertTableColumnFormattedStateSet(
                'valid_until',
                '31 Dec, 2026',
                $fare,
            );
    });
});
