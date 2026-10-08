<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Route Pattern Calculation', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');
        config(['services.osrm.url' => 'https://osrm.test']);

        Http::preventStrayRequests();

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();

        $this->stops = [
            Stop::factory()->for($this->company)->create([
                'latitude' => '10.4000000',
                'longitude' => '-84.0000000',
            ]),
            Stop::factory()->for($this->company)->create([
                'latitude' => '10.4500000',
                'longitude' => '-84.0500000',
            ]),
            Stop::factory()->for($this->company)->create([
                'latitude' => '10.5000000',
                'longitude' => '-84.1000000',
            ]),
        ];

        foreach ($this->stops as $index => $stop) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 15,
            ]);
        }

        $this->geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-84.0500000, 10.4500000],
                [-84.0223456, 10.4623456],
            ],
        ];

        $this->routingResponse = [
            'code' => 'Ok',
            'routes' => [
                [
                    'distance' => 1200.5,
                    'duration' => 180.0,
                    'geometry' => $this->geometry,
                ],
            ],
        ];

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('calculates through the selected endpoints and stored intermediate stops without saving', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response(
                $this->routingResponse,
            ),
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Selected Origin',
                'display_name' => 'Selected Origin',
            ]))
            ->set('data.destination_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4623456,
                    'lng' => -84.0223456,
                ],
                'name' => 'Selected Destination',
                'display_name' => 'Selected Destination',
            ]))
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertHasNoErrors()
            ->assertNotified('Route Calculated')
            ->assertSet('data.calculated_geometry', $this->geometry)
            ->assertSet('data.distance_meters', 1200.5)
            ->assertSet('data.driving_duration_seconds', 180.0);

        Http::assertSent(function ($request): bool {
            $path = rawurldecode(
                (string) parse_url($request->url(), PHP_URL_PATH),
            );

            return $path === '/route/v1/driving/'
                .'-84.0123456,10.4523456;'
                .'-84.0500000,10.4500000;'
                .'-84.0223456,10.4623456';
        });

        expect($this->pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe(array_map(
                fn (Stop $stop): string => $stop->getKey(),
                $this->stops,
            ))
            ->and($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30])
            ->and(Stop::query()->count())->toBe(3);
    });

    test('clears a calculated proposal when an endpoint changes', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response(
                $this->routingResponse,
            ),
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertSet('data.calculated_geometry', $this->geometry)
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Changed Origin',
                'display_name' => 'Changed Origin',
            ]))
            ->assertSet('data.calculated_geometry', null)
            ->assertSet('data.distance_meters', null)
            ->assertSet('data.driving_duration_seconds', null)
            ->assertSet('data.distance_preview', null)
            ->assertSet('data.driving_duration_preview', null);

        Http::assertSentCount(1);
    });

    test('shows a notification when no road route is available', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'NoRoute',
                'routes' => [],
            ]),
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertNotified('Could Not Calculate Route')
            ->assertSet('data.calculated_geometry', null)
            ->assertSet('data.distance_meters', null)
            ->assertSet('data.driving_duration_seconds', null);

        expect($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);
    });

    test('calculates through adjustment points between their stop occurrences without creating boarding stops', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0000000, 10.4000000],
                [-84.0250000, 10.4250000],
                [-84.0500000, 10.4500000],
                [-84.1000000, 10.5000000],
            ],
        ];

        $response = $this->routingResponse;
        $response['routes'][0]['geometry'] = $geometry;

        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response(
                $response,
            ),
        ]);

        $stopsBefore = Stop::withTrashed()->count();
        $occurrenceIds = $occurrences->pluck('id')->all();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.routing_adjustments', [
                [
                    'from_occurrence_id' => $occurrences[0]->getKey(),
                    'to_occurrence_id' => $occurrences[1]->getKey(),
                    'points' => [
                        ['lat' => 10.425, 'lng' => -84.025],
                    ],
                ],
            ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertHasNoErrors()
            ->assertSet('data.calculated_geometry', $geometry);

        Http::assertSent(function ($request): bool {
            $path = rawurldecode(
                (string) parse_url($request->url(), PHP_URL_PATH),
            );

            return $path === '/route/v1/driving/'
                .'-84.0000000,10.4000000;'
                .'-84.0250000,10.4250000;'
                .'-84.0500000,10.4500000;'
                .'-84.1000000,10.5000000';
        });

        Http::assertSentCount(1);

        expect(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30])
            ->and($this->pattern->fresh()->route_geometry)->toBeNull();
    });

    test('rejects adjustments between nonconsecutive stops without contacting the routing provider', function (): void {
        Http::fake();

        $occurrences = $this->pattern->stopOccurrences()->get();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.routing_adjustments', [
                [
                    'from_occurrence_id' => $occurrences[0]->getKey(),
                    'to_occurrence_id' => $occurrences[2]->getKey(),
                    'points' => [
                        ['lat' => 10.425, 'lng' => -84.025],
                    ],
                ],
            ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertHasNoErrors()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Could Not Calculate Route')
                    ->body(
                        'Review the route adjustment points and available stops before calculating.',
                    ),
            )
            ->assertSet('data.calculated_geometry', null)
            ->assertSet('data.distance_meters', null)
            ->assertSet('data.driving_duration_seconds', null);

        Http::assertNothingSent();

        expect($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all())
            ->and($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);
    });

    test('clears the calculated proposal when adjustment points change without calculating automatically', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response(
                $this->routingResponse,
            ),
        ]);

        $occurrences = $this->pattern->stopOccurrences()->get();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertSet('data.calculated_geometry', $this->geometry)
            ->set('data.routing_adjustments', [
                [
                    'from_occurrence_id' => $occurrences[0]->getKey(),
                    'to_occurrence_id' => $occurrences[1]->getKey(),
                    'points' => [
                        ['lat' => 10.425, 'lng' => -84.025],
                    ],
                ],
            ])
            ->assertSet('data.calculated_geometry', null)
            ->assertSet('data.distance_meters', null)
            ->assertSet('data.driving_duration_seconds', null)
            ->assertSet('data.distance_preview', null)
            ->assertSet('data.driving_duration_preview', null);

        Http::assertSentCount(1);

        expect($this->pattern->fresh()->route_geometry)->toBeNull()
            ->and($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);
    });

    test('displays calculated metrics in disabled non-dehydrated inputs', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response(
                $this->routingResponse,
            ),
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertHasNoErrors()
            ->assertSchemaComponentExists(
                'distance_preview',
                checkComponentUsing: function ($component): bool {
                    expect($component)->toBeInstanceOf(TextInput::class);

                    expect($component->isDisabled())->toBeTrue()
                        ->and($component->isDehydrated())->toBeFalse()
                        ->and($component->getState())->toBe('1.20')
                        ->and($component->getSuffixLabel())->toBe('km');

                    return true;
                },
            )
            ->assertSchemaComponentExists(
                'driving_duration_preview',
                checkComponentUsing: function ($component): bool {
                    expect($component)->toBeInstanceOf(TextInput::class);

                    expect($component->isDisabled())->toBeTrue()
                        ->and($component->isDehydrated())->toBeFalse()
                        ->and((string) $component->getState())->toBe('3')
                        ->and(trim((string) $component->getSuffixLabel()))->toBe('min');

                    return true;
                },
            );
    });

    test('loads saved metrics into disabled preview inputs', function (string $distance, string $duration, string $expectedDistance, int $expectedMinutes, ): void {
        Http::fake();

        $this->pattern->update([
            'route_geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [-84.0, 10.4],
                    [-84.05, 10.45],
                    [-84.1, 10.5],
                ],
            ],
            'distance_meters' => $distance,
            'driving_duration_seconds' => $duration,
            'routing_points_hash' => hash('sha256', 'saved-calculation'),
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaComponentExists(
                'distance_preview',
                checkComponentUsing: function (TextInput $component) use ($expectedDistance): bool {
                    expect($component->getState())->toBe($expectedDistance)
                        ->and($component->isDisabled())->toBeTrue()
                        ->and($component->isDehydrated())->toBeFalse();

                    return true;
                },
            )
            ->assertSchemaComponentExists(
                'driving_duration_preview',
                checkComponentUsing: function (TextInput $component) use ($expectedMinutes): bool {
                    expect((int) $component->getState())->toBe($expectedMinutes)
                        ->and($component->isDisabled())->toBeTrue()
                        ->and($component->isDehydrated())->toBeFalse();

                    return true;
                },
            );

        Http::assertNothingSent();
    })->with([
                'saved metrics' => ['1200.50', '181.00', '1.20', 4],
                'zero metrics' => ['0.00', '0.00', '0.00', 0],
            ]);
});
