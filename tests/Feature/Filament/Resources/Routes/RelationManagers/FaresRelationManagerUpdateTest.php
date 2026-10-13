<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RelationManagers\FaresRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RouteFare;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Fare Relation Manager Update', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();

        $this->fare = RouteFare::factory()->create([
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('updates a fare for authorized users', function (string $role): void {
        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $this->company);

            CompanyUser::create([
                'company_id' => $this->company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
                'Update:Route',
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('edit')->table($this->fare), [
                'amount' => '1.50',
                'currency' => 'USD',
                'valid_from' => '2026-11-01',
                'valid_until' => '2027-01-31',
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'route_id' => $this->route->getKey(),
            'amount' => '1.50',
            'currency' => 'USD',
            'valid_from' => '2026-11-01',
            'valid_until' => '2027-01-31',
        ]);

        expect($this->route->fares()->count())->toBe(1);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('allows saving a fare without changing its existing values', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->mountAction(TestAction::make('edit')->table($this->fare))
            ->assertActionMounted(
                TestAction::make('edit')->table($this->fare),
            )
            ->assertSchemaStateSet([
                'amount' => '500.00',
                'currency' => 'CRC',
                'valid_from' => '2026-10-01',
                'valid_until' => '2026-12-31',
            ])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect($this->route->fares()->count())->toBe(1);
    });

    test('rejects changes that overlap another fare and preserves both records', function (): void {
        $otherFare = RouteFare::factory()->create([
            'route_id' => $this->route->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
            'valid_from' => '2027-01-01',
            'valid_until' => '2027-03-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('edit')->table($this->fare), [
                'amount' => '600.00',
                'currency' => 'CRC',
                'valid_from' => '2026-10-01',
                'valid_until' => '2027-01-15',
            ])
            ->assertHasFormErrors([
                'currency' => 'A fare of 750.00 CRC (from January 1, 2027 to March 31, 2027) already applies during the selected validity period.',
            ]);

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $otherFare->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
            'valid_from' => '2027-01-01',
            'valid_until' => '2027-03-31',
        ]);

        expect($this->route->fares()->count())->toBe(2);
    });

    test('rejects editing without permission to update the route', function (): void {
        $actor = createUserWithRole('route-viewer', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $this->fare->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('rejects editing from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => ViewRoute::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $this->fare->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('does not edit a fare belonging to another route', function (): void {
        $otherRoute = Route::factory()->for($this->company)->create();

        $foreignFare = RouteFare::factory()->create([
            'route_id' => $otherRoute->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $foreignFare->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $foreignFare->getKey(),
            'route_id' => $otherRoute->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('rejects invalid changes and preserves the existing fare', function (array $overrides, string $field, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(
                TestAction::make('edit')->table($this->fare),
                array_replace([
                    'amount' => '500.00',
                    'currency' => 'CRC',
                    'valid_from' => '2026-10-01',
                    'valid_until' => '2026-12-31',
                ], $overrides),
            )
            ->assertHasFormErrors([$field => $rule]);

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $this->fare->getKey(),
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect($this->route->fares()->count())->toBe(1);
    })->with([
        'negative amount' => [
            ['amount' => '-1.00'],
            'amount',
            'min',
        ],
        'excess decimal places' => [
            ['amount' => '500.125'],
            'amount',
            'decimal',
        ],
        'unsupported currency' => [
            ['currency' => 'EUR'],
            'currency',
            'in',
        ],
        'inverted validity dates' => [
            ['valid_until' => '2026-09-30'],
            'valid_until',
            'after_or_equal',
        ],
    ]);
});
