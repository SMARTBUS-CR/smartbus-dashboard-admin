<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Schedule Relation Manager Creation', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();
    });

    test('creates a weekly departure for authorized users', function (string $role): void {
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
                'ViewAny:RoutePattern',
                'View:RoutePattern',
                'Update:RoutePattern',
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
                'valid_from' => '2026-10-01',
                'valid_until' => '2026-12-31',
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('allows a Sunday departure without validity dates', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'day_of_week' => 0,
                'departure_time' => '00:00:00',
                'valid_from' => null,
                'valid_until' => null,
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 0,
            'departure_time' => '00:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('rejects invalid schedule data', function (array $overrides, string $field, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(
                TestAction::make('create')->table(),
                array_replace([
                    'day_of_week' => 1,
                    'departure_time' => '06:30:00',
                    'valid_from' => null,
                    'valid_until' => null,
                ], $overrides),
            )
            ->assertHasFormErrors([$field => $rule]);

        expect($this->pattern->schedules()->count())->toBe(0);
    })->with([
        'missing day' => [
            ['day_of_week' => null],
            'day_of_week',
            'required',
        ],
        'invalid day' => [
            ['day_of_week' => 7],
            'day_of_week',
            'in',
        ],
        'missing departure time' => [
            ['departure_time' => null],
            'departure_time',
            'required',
        ],
        'invalid departure time' => [
            ['departure_time' => '25:00:00'],
            'departure_time',
            'date_format',
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

    test('rejects creation without permission to update the pattern', function (): void {
        $actor = createUserWithRole('route-viewer', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->pattern->schedules()->count())->toBe(0);
    });

    test('rejects creation from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->pattern->schedules()->count())->toBe(0);
    });

    test('rejects overlapping validity periods for the same weekly departure', function (?string $existingFrom, ?string $existingUntil, ?string $newFrom, ?string $newUntil): void {
        RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => $existingFrom,
            'valid_until' => $existingUntil,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
                'valid_from' => $newFrom,
                'valid_until' => $newUntil,
            ])
            ->assertHasFormErrors([
                'departure_time' => __(
                    'A departure already exists for this day and time during the selected validity period.',
                ),
            ]);

        expect($this->pattern->schedules()->count())->toBe(1);
    })->with([
        'overlapping bounded periods' => [
            '2026-10-01', '2026-10-31',
            '2026-10-15', '2026-11-15',
        ],
        'shared boundary date' => [
            '2026-10-01', '2026-10-31',
            '2026-10-31', '2026-11-30',
        ],
        'existing period without boundaries' => [
            null, null,
            '2026-10-01', '2026-10-31',
        ],
        'new period without boundaries' => [
            '2026-10-01', '2026-10-31',
            null, null,
        ],
    ]);

    test('allows the same weekly departure during separate validity periods', function (): void {
        RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-10-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
                'valid_from' => '2026-11-01',
                'valid_until' => '2026-11-30',
            ])
            ->assertNotified();

        expect($this->pattern->schedules()->count())->toBe(2);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);
    });
});
