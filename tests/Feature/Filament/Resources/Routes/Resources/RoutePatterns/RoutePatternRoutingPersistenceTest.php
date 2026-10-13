<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Route Pattern Routing Persistence', function (): void {
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
                [-84.0000000, 10.4000000],
                [-84.0500000, 10.4500000],
                [-84.1000000, 10.5000000],
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

    test('saves the calculated route without requesting it again or changing arrival estimates', function (): void {
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
            ->assertSet('data.calculated_geometry', $this->geometry)
            ->call('save')
            ->assertHasNoFormErrors();

        $pattern = $this->pattern->fresh();

        expect($pattern->route_geometry)
            ->toEqual($this->geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00')
            ->and($pattern->routing_points_hash)->toBe(hash(
                'sha256',
                '-84.0000000,10.4000000;'
                .'-84.0500000,10.4500000;'
                .'-84.1000000,10.5000000',
            ))
            ->and($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe(array_map(
                fn (Stop $stop): string => $stop->getKey(),
                $this->stops,
            ))
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);

        Http::assertSentCount(1);
    });

    test('saves server calculated values when the submitted proposal is modified', function (): void {
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
            ->set('data.calculated_geometry', [
                'type' => 'LineString',
                'coordinates' => [
                    [-80, 8],
                    [-81, 9],
                ],
            ])
            ->set('data.distance_meters', 1)
            ->set('data.driving_duration_seconds', 1)
            ->call('save')
            ->assertHasNoFormErrors();

        $pattern = $this->pattern->fresh();

        expect($pattern->route_geometry)
            ->toEqual($this->geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00');

        Http::assertSentCount(1);
    });

    test('rejects a proposal without a cached calculation and rolls back the update', function (): void {
        Http::fake();

        $originalName = $this->pattern->name;
        $originalStopIds = $this->pattern->stopOccurrences()
            ->pluck('stop_id')
            ->all();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.name', 'Name That Must Not Be Saved')
            ->set('data.calculated_geometry', $this->geometry)
            ->set('data.distance_meters', 1200.5)
            ->set('data.driving_duration_seconds', 180)
            ->call('save')
            ->assertHasFormErrors([
                'origin_search' => 'Calculate the route again before saving.',
            ]);

        $pattern = $this->pattern->fresh();

        expect($pattern->name)->toBe($originalName)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull()
            ->and($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe($originalStopIds)
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);

        Http::assertNothingSent();
    });

    test('restores the saved route proposal when reopening the edit page without requesting the provider', function (): void {
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
            ->call('save')
            ->assertHasNoFormErrors();

        $pattern = $this->pattern->fresh();

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaStateSet([
                'calculated_geometry' => $pattern->route_geometry,
                'distance_meters' => $pattern->distance_meters,
                'driving_duration_seconds' => $pattern->driving_duration_seconds,
            ]);

        Http::assertSentCount(1);
    });

    test('preserves the saved route when editing its name after the calculation cache expires', function (): void {
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
            ->call('save')
            ->assertHasNoFormErrors();

        $savedPattern = $this->pattern->fresh();

        $coordinates = '-84.0000000,10.4000000;'
            .'-84.0500000,10.4500000;'
            .'-84.1000000,10.5000000';

        Cache::forget(
            'osrm.route.'.hash(
                'sha256',
                rtrim((string) config('services.osrm.url'), '/')
                .'|'.$coordinates,
            ),
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $savedPattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.name', 'Updated Pattern Name')
            ->call('save')
            ->assertHasNoFormErrors();

        $updatedPattern = $this->pattern->fresh();

        expect($updatedPattern->name)->toBe('Updated Pattern Name')
            ->and($updatedPattern->route_geometry)
            ->toEqual($savedPattern->route_geometry)
            ->and($updatedPattern->distance_meters)
            ->toBe($savedPattern->distance_meters)
            ->and($updatedPattern->driving_duration_seconds)
            ->toBe($savedPattern->driving_duration_seconds)
            ->and($updatedPattern->routing_points_hash)
            ->toBe($savedPattern->routing_points_hash)
            ->and($updatedPattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);

        Http::assertSentCount(1);
    });

    test('rolls back endpoint and pattern changes when the proposed route has no matching cached calculation', function (): void {
        Http::fake();

        $originalName = $this->pattern->name;
        $originalStopIds = $this->pattern->stopOccurrences()
            ->pluck('stop_id')
            ->all();

        $stopsBefore = Stop::withTrashed()->count();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.name', 'Name That Must Not Be Saved')
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'New Origin',
                'display_name' => 'New Origin',
            ]))
            ->set('data.destination_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4623456,
                    'lng' => -84.0223456,
                ],
                'name' => 'New Destination',
                'display_name' => 'New Destination',
            ]))
            ->set('data.calculated_geometry', $this->geometry)
            ->set('data.distance_meters', 1200.5)
            ->set('data.driving_duration_seconds', 180)
            ->call('save')
            ->assertHasFormErrors([
                'origin_search' => 'Calculate the route again before saving.',
            ]);

        $pattern = $this->pattern->fresh();

        expect($pattern->name)->toBe($originalName)
            ->and($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe($originalStopIds)
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30])
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();

        Http::assertNothingSent();
    });

    test('saves adjustment points with their calculated route and restores them when reopening the form', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustments = [
            [
                'from_occurrence_id' => $occurrences[0]->getKey(),
                'to_occurrence_id' => $occurrences[1]->getKey(),
                'points' => [
                    ['lat' => 10.425, 'lng' => -84.025],
                ],
            ],
        ];

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

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.routing_adjustments', $adjustments)
            ->callAction(
                TestAction::make('calculateRoute')
                    ->schemaComponent('routing_actions'),
            )
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoFormErrors();

        $pattern = $this->pattern->fresh();

        expect($pattern->routing_adjustments)->toEqual($adjustments)
            ->and($pattern->route_geometry)->toEqual($geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00')
            ->and($pattern->routing_points_hash)->toBe(hash(
                'sha256',
                '-84.0000000,10.4000000;'
                .'-84.0250000,10.4250000;'
                .'-84.0500000,10.4500000;'
                .'-84.1000000,10.5000000',
            ))
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all())
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 15, 30]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaStateSet([
                'routing_adjustments' => $pattern->routing_adjustments,
                'calculated_geometry' => $pattern->route_geometry,
                'distance_meters' => $pattern->distance_meters,
                'driving_duration_seconds' => $pattern->driving_duration_seconds,
            ]);

        Http::assertSentCount(1);
    });
});
