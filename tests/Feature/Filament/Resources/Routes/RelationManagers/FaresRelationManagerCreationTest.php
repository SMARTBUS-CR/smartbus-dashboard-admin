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

describe('Route Fare Relation Manager Creation', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
    });

    test('creates a fare for authorized users', function (string $role): void {
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
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '500.50',
                'currency' => 'CRC',
                'valid_from' => '2026-10-01',
                'valid_until' => '2026-12-31',
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '500.50',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect($this->route->fares()->count())->toBe(1);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('allows an explicitly free fare without validity dates', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '0',
                'currency' => 'CRC',
                'valid_from' => null,
                'valid_until' => null,
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '0.00',
            'currency' => 'CRC',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('accepts supported currencies', function (string $currency): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '10.25',
                'currency' => $currency,
                'valid_from' => null,
                'valid_until' => null,
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '10.25',
            'currency' => $currency,
        ]);
    })->with([
        'Belize dollar' => ['BZD'],
        'Costa Rican colon' => ['CRC'],
        'Guatemalan quetzal' => ['GTQ'],
        'Honduran lempira' => ['HNL'],
        'Nicaraguan cordoba' => ['NIO'],
        'Panamanian balboa' => ['PAB'],
        'US dollar' => ['USD'],
    ]);

    test('rejects invalid fare data without storing a fare', function (array $overrides, string $field, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(
                TestAction::make('create')->table(),
                array_replace([
                    'amount' => '500.00',
                    'currency' => 'CRC',
                    'valid_from' => null,
                    'valid_until' => null,
                ], $overrides),
            )
            ->assertHasFormErrors([$field => $rule]);

        expect($this->route->fares()->count())->toBe(0);
    })->with([
        'missing amount' => [
            ['amount' => null],
            'amount',
            'required',
        ],
        'non-numeric amount' => [
            ['amount' => 'invalid'],
            'amount',
            'numeric',
        ],
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
        'amount exceeds storage capacity' => [
            ['amount' => '10000000000.00'],
            'amount',
            'max',
        ],
        'missing currency' => [
            ['currency' => null],
            'currency',
            'required',
        ],
        'unsupported currency' => [
            ['currency' => 'EUR'],
            'currency',
            'in',
        ],
        'inverted validity dates' => [
            [
                'valid_from' => '2026-12-31',
                'valid_until' => '2026-10-01',
            ],
            'valid_until',
            'after_or_equal',
        ],
    ]);

    test('rejects creation without permission to update the route', function (): void {
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
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->route->fares()->count())->toBe(0);
    });

    test('rejects creation from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => ViewRoute::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->route->fares()->count())->toBe(0);
    });

    test('rejects overlapping fares in the same currency', function (?string $existingFrom, ?string $existingUntil, ?string $newFrom, ?string $newUntil, string $expectedPeriod): void {
        RouteFare::factory()->create([
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => $existingFrom,
            'valid_until' => $existingUntil,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '750.00',
                'currency' => 'CRC',
                'valid_from' => $newFrom,
                'valid_until' => $newUntil,
            ])
            ->assertHasFormErrors([
                'currency' => 'A fare of 500.00 CRC ('
                    .$expectedPeriod
                    .') already applies during the selected validity period.',
            ]);

        expect($this->route->fares()->count())->toBe(1);

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => $existingFrom,
            'valid_until' => $existingUntil,
        ]);
    })->with([
        'overlapping bounded periods' => [
            '2026-10-01', '2026-10-31',
            '2026-10-15', '2026-11-15',
            'from October 1, 2026 to October 31, 2026',
        ],
        'shared boundary date' => [
            '2026-10-01', '2026-10-31',
            '2026-10-31', '2026-11-30',
            'from October 1, 2026 to October 31, 2026',
        ],
        'existing period without boundaries' => [
            null, null,
            '2026-10-01', '2026-10-31',
            'with no start or end date',
        ],
        'new period without boundaries' => [
            '2026-10-01', '2026-10-31',
            null, null,
            'from October 1, 2026 to October 31, 2026',
        ],
    ]);

    test('allows fares in the same currency during separate validity periods', function (): void {
        RouteFare::factory()->create([
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-10-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $this->route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '750.00',
                'currency' => 'CRC',
                'valid_from' => '2026-11-01',
                'valid_until' => '2026-11-30',
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '750.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        expect($this->route->fares()->count())->toBe(2);
    });

    test('allows fares in different currencies during the same validity period', function (): void {
        RouteFare::factory()->create([
            'route_id' => $this->route->getKey(),
            'amount' => '500.00',
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
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '1.00',
                'currency' => 'USD',
                'valid_from' => null,
                'valid_until' => null,
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteFare::class, [
            'route_id' => $this->route->getKey(),
            'amount' => '1.00',
            'currency' => 'USD',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        expect($this->route->fares()->count())->toBe(2);
    });
});
