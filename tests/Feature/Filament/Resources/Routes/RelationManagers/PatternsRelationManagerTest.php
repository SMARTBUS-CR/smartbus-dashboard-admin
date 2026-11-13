<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RelationManagers\PatternsRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Relation Manager Access', function (): void {
    test('lists only the owner route patterns for an authorized user', function (string $role, string $pageClass): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $ownPattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Outbound',
            'headsign' => 'Heredia',
        ]);

        $foreignPattern = RoutePattern::factory()
            ->for($otherRoute)
            ->create();

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            $permissions = [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
            ];

            if ($pageClass === EditRoute::class) {
                $permissions[] = 'Update:Route';
            }

            grantShield($actor, $permissions, $company);
        }

        actingAsInCompany($actor, $company);

        $component = Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => $pageClass,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownPattern])
            ->assertCanNotSeeTableRecords([$foreignPattern])
            ->assertSee('OUTBOUND')
            ->assertSee('Heredia');

        if ($pageClass === ViewRoute::class) {
            $component->assertActionHidden(
                TestAction::make('create')->table(),
            );
        }
    })->with([
        'system admin' => [
            UserRole::SuperAdmin->value,
            EditRoute::class,
        ],
        'company admin with permissions' => [
            UserRole::Admin->value,
            EditRoute::class,
        ],
        'custom role with viewing permissions' => [
            'route-viewer',
            ViewRoute::class,
        ],
    ]);

    test('does not allow a relation manager for another selected company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(PatternsRelationManager::canViewForRecord(
            $foreignRoute,
            EditRoute::class,
        ))->toBeFalse();
    });

    test('does not expose patterns without their listing permission', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

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

        expect(PatternsRelationManager::canViewForRecord(
            $route,
            ViewRoute::class,
        ))->toBeFalse();
    });
});
