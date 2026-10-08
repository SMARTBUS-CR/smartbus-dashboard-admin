<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\StopOccurrencesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

describe('Route Pattern Inline Stop Creation', function (): void {
    test('offers inline stop creation to a system admin', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldExists(
                'stop_id',
                function (Select $field): bool {
                    $action = $field->getCreateOptionAction();

                    return $action !== null
                        && $action->isAuthorized()
                        && ! $action->isHidden();
                },
            );
    });

    test('creates a company stop inline and adds it to the pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $component = Livewire::test(
            StopOccurrencesRelationManager::class,
            [
                'ownerRecord' => $pattern,
                'pageClass' => EditRoutePattern::class,
            ],
        )
            ->mountAction(TestAction::make('create')->table())
            ->callAction(
                TestAction::make('createOption')->schemaComponent(
                    'stop_id',
                    'mountedActionSchema0',
                ),
                [
                    'is_shared' => false,
                    'name' => 'Inline Boarding Point',
                    'description' => 'Created without leaving the pattern.',
                    'latitude' => '10.4523456',
                    'longitude' => '-84.0123456',
                ],
            )
            ->assertHasNoErrors();

        $stop = Stop::query()
            ->where('name', 'Inline Boarding Point')
            ->sole();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => $company->getKey(),
            'description' => 'Created without leaving the pattern.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $component->assertSchemaStateSet([
            'stop_id' => $stop->getKey(),
        ]);

        expect($pattern->stopOccurrences()->exists())->toBeFalse();

        $component
            ->fillForm([
                'minutes_from_start' => 0,
            ])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        expect($pattern->stopOccurrences()->count())->toBe(1);
    });

    test('requires stop creation permission for inline creation', function (bool $canCreateStops, ): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $actor = createUserWithRole('route-editor', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        $permissions = [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
            'Update:RoutePattern',
        ];

        if ($canCreateStops) {
            $permissions[] = 'Create:Stop';
        }

        grantShield($actor, $permissions, $company);
        actingAsInCompany($actor, $company);

        $stopsBefore = Stop::count();

        $component = Livewire::test(
            StopOccurrencesRelationManager::class,
            [
                'ownerRecord' => $pattern,
                'pageClass' => EditRoutePattern::class,
            ],
        )->mountAction(TestAction::make('create')->table());

        $action = TestAction::make('createOption')->schemaComponent(
            'stop_id',
            'mountedActionSchema0',
        );

        if (! $canCreateStops) {
            $component
                ->assertActionHidden($action)
                ->call(
                    'mountAction',
                    'createOption',
                    [],
                    [
                        'schemaComponent' => 'mountedActionSchema0.stop_id',
                    ],
                )
                ->assertSet('mountedActions', function (array $actions): bool {
                    return count($actions) === 1
                        && $actions[0]['name'] === 'create';
                });

            expect(Stop::count())->toBe($stopsBefore)
                ->and($pattern->stopOccurrences()->exists())->toBeFalse();

            return;
        }

        $component
            ->callAction($action, [
                'is_shared' => true,
                'name' => 'Company Boarding Point',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->assertHasNoErrors();

        $stop = Stop::query()
            ->where('name', 'Company Boarding Point')
            ->sole();

        expect(Stop::count())->toBe($stopsBefore + 1)
            ->and($stop->company_id)->toBe($company->getKey())
            ->and($pattern->stopOccurrences()->exists())->toBeFalse();

        $component->assertSchemaStateSet([
            'stop_id' => $stop->getKey(),
        ]);
    })->with([
                'without stop creation permission' => false,
                'with stop creation permission' => true,
            ]);

    test('allows a system admin to create a shared stop inline', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $component = Livewire::test(
            StopOccurrencesRelationManager::class,
            [
                'ownerRecord' => $pattern,
                'pageClass' => EditRoutePattern::class,
            ],
        )
            ->mountAction(TestAction::make('create')->table())
            ->callAction(
                TestAction::make('createOption')->schemaComponent(
                    'stop_id',
                    'mountedActionSchema0',
                ),
                [
                    'is_shared' => true,
                    'name' => 'Shared Inline Terminal',
                    'latitude' => '10.4523456',
                    'longitude' => '-84.0123456',
                ],
            )
            ->assertHasNoErrors();

        $stop = Stop::query()
            ->where('name', 'Shared Inline Terminal')
            ->sole();

        expect($stop->company_id)->toBeNull();

        $component
            ->assertSchemaStateSet([
                'stop_id' => $stop->getKey(),
            ])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect($stop->fresh()->company_id)->toBeNull();
    });

    test('rejects invalid inline stop data without creating records', function (array $overrides, array $errors, ): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $stopsBefore = Stop::count();

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->mountAction(TestAction::make('create')->table())
            ->callAction(
                TestAction::make('createOption')->schemaComponent(
                    'stop_id',
                    'mountedActionSchema0',
                ),
                array_replace([
                    'is_shared' => false,
                    'name' => 'Invalid Inline Stop',
                    'latitude' => '10.4523456',
                    'longitude' => '-84.0123456',
                ], $overrides),
            )
            ->assertHasFormErrors($errors);

        expect(Stop::count())->toBe($stopsBefore)
            ->and($pattern->stopOccurrences()->exists())->toBeFalse();
    })->with([
                'missing name' => [
                    ['name' => ''],
                    ['name' => 'required'],
                ],
                'invalid latitude' => [
                    ['latitude' => '91'],
                    ['latitude' => 'between'],
                ],
                'invalid longitude' => [
                    ['longitude' => '-181'],
                    ['longitude' => 'between'],
                ],
            ]);
});