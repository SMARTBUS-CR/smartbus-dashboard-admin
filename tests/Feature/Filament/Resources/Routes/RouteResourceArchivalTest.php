<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\ListRoutes;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Resource Archival', function (): void {
    test('archives a route while preserving its related configuration', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
        ]);

        $fare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield(
                $actor,
                ['ViewAny:Route', 'View:Route', 'Delete:Route'],
                $company,
            );
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->callAction(TestAction::make('delete')->table($route))
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$route]);

        $this->assertSoftDeleted($route);
        $this->assertNotSoftDeleted($pattern);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'route_pattern_id' => $pattern->getKey(),
        ]);

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $fare->getKey(),
            'route_id' => $route->getKey(),
        ]);
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-manager',
    ]);

    test('restores an archived route through the archived records filter', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $route->delete();

        $actor = createUserWithRole('route-manager', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route', 'Restore:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$route])
            ->callAction(TestAction::make('restore')->table($route))
            ->assertNotified();

        $this->assertNotSoftDeleted($route);

        Livewire::test(ListRoutes::class)
            ->assertCanSeeTableRecords([$route]);
    });

    test('hides archival from a user with viewing permissions only', function (): void {
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

        Livewire::test(ListRoutes::class)
            ->assertActionHidden(TestAction::make('delete')->table($route));

        $this->assertNotSoftDeleted($route);
    });

    test('does not expose permanent deletion to a system admin', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ListRoutes::class)
            ->assertActionDoesNotExist(
                TestAction::make('forceDelete')->table($route),
            );
    });
});
