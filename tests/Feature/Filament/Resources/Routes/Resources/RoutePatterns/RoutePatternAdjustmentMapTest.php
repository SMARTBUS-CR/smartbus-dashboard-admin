<?php

use App\Enums\LucideIcon;
use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Route Pattern Adjustment Map', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();

        $this->originStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4000000',
            'longitude' => '-84.0000000',
        ]);

        $this->middleStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4500000',
            'longitude' => '-84.0500000',
        ]);

        $this->destinationStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.5000000',
            'longitude' => '-84.1000000',
        ]);

        foreach ([
            $this->originStop,
            $this->middleStop,
            $this->destinationStop,
        ] as $index => $stop) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => null,
            ]);
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('adds a pending adjustment point to the selected segment after a map click without saving or calculating', function (): void {
        Http::fake();

        $occurrences = $this->pattern->stopOccurrences()->get();
        $stopsBefore = Stop::withTrashed()->count();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.adjustment_mode', true)
            ->assertSchemaComponentExists(
                'adjustment_segment',
                checkComponentUsing: function (Select $component) use ($occurrences): bool {
                    expect($component->getOptions())->toBe([
                        $occurrences[0]->getKey() => $this->originStop->name.' → '.$this->middleStop->name,
                        $occurrences[1]->getKey() => $this->middleStop->name.' → '.$this->destinationStop->name,
                    ]);

                    return true;
                },
            )
            ->set(
                'data.adjustment_segment',
                $occurrences[0]->getKey(),
            )
            ->call(
                'callSchemaComponentMethod',
                'form.route_map',
                'handleMapClick',
                [
                    'latitude' => 10.425,
                    'longitude' => -84.025,
                ],
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'routing_adjustments' => [
                    [
                        'from_occurrence_id' => $occurrences[0]->getKey(),
                        'to_occurrence_id' => $occurrences[1]->getKey(),
                        'points' => [
                            ['lat' => 10.425, 'lng' => -84.025],
                        ],
                    ],
                ],
            ]);

        expect($this->pattern->fresh()->routing_adjustments)->toBe([])
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all());

        Http::assertNothingSent();
    });

    test('shows pending adjustment markers separately from boarding stop markers', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

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
                        ['lat' => 10.435, 'lng' => -84.035],
                    ],
                ],
            ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $markers = collect(
                        $component->getMapData()['layersData'],
                    )->where('type', 'marker');

                    $adjustmentMarkers = $markers->filter(
                        fn (array $layer): bool => str_starts_with($layer['id'], 'adjustment:'),
                    );

                    $stopMarkers = $markers->reject(
                        fn (array $layer): bool => str_starts_with($layer['id'], 'adjustment:'),
                    );

                    expect($adjustmentMarkers->pluck('coords')->values()->all())
                        ->toEqual([
                            [10.425, -84.025],
                            [10.435, -84.035],
                        ])
                        ->and($stopMarkers->pluck('coords')->values()->all())->toEqual([
                            [10.4, -84.0],
                            [10.45, -84.05],
                            [10.5, -84.1],
                        ])
                        ->and($adjustmentMarkers)->toHaveCount(2);

                    foreach ($adjustmentMarkers as $marker) {
                        expect(data_get($marker, 'icon.heroicon'))
                            ->toBe(svg(LucideIcon::Route->value)->toHtml())
                            ->and(data_get($marker, 'icon.text'))->toBeNull();
                    }

                    return true;
                },
            );

        expect($this->pattern->fresh()->routing_adjustments)->toBe([])
            ->and($this->pattern->stopOccurrences()->count())->toBe(3);
    });

    test('removes the selected adjustment point while preserving other points and segments', function (string $scenario): void {
        Http::fake();

        $occurrences = $this->pattern->stopOccurrences()->get();

        $firstSegment = [
            'from_occurrence_id' => $occurrences[0]->getKey(),
            'to_occurrence_id' => $occurrences[1]->getKey(),
            'points' => $scenario === 'middle point'
                ? [
                    ['lat' => 10.415, 'lng' => -84.015],
                    ['lat' => 10.425, 'lng' => -84.025],
                    ['lat' => 10.435, 'lng' => -84.035],
                ]
                : [
                    ['lat' => 10.425, 'lng' => -84.025],
                ],
        ];

        $secondSegment = [
            'from_occurrence_id' => $occurrences[1]->getKey(),
            'to_occurrence_id' => $occurrences[2]->getKey(),
            'points' => [
                ['lat' => 10.475, 'lng' => -84.075],
            ],
        ];

        $pointIndex = $scenario === 'middle point' ? 1 : 0;

        $expectedAdjustments = $scenario === 'middle point'
            ? [
                array_replace($firstSegment, [
                    'points' => [
                        $firstSegment['points'][0],
                        $firstSegment['points'][2],
                    ],
                ]),
                $secondSegment,
            ]
            : [$secondSegment];

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.adjustment_mode', true)
            ->set('data.routing_adjustments', [
                $firstSegment,
                $secondSegment,
            ])
            ->set('data.calculated_geometry', [
                'type' => 'LineString',
                'coordinates' => [
                    [-84.0, 10.4],
                    [-84.1, 10.5],
                ],
            ])
            ->set('data.distance_meters', 1200)
            ->set('data.driving_duration_seconds', 180)
            ->call(
                'callSchemaComponentMethod',
                'form.route_map',
                'handleLayerClick',
                [
                    'layerId' => 'adjustment:'
                        .$firstSegment['from_occurrence_id'].':'
                        .$firstSegment['to_occurrence_id'].':'
                        .$pointIndex,
                ],
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'routing_adjustments' => $expectedAdjustments,
                'calculated_geometry' => null,
                'distance_meters' => null,
                'driving_duration_seconds' => null,
            ])->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component) use ($expectedAdjustments): bool {
                    $coordinates = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->filter(
                            fn (array $layer): bool => str_starts_with($layer['id'], 'adjustment:'),
                        )
                        ->pluck('coords')
                        ->values()
                        ->all();

                    $expectedCoordinates = collect($expectedAdjustments)
                        ->flatMap(
                            fn (array $adjustment): array => $adjustment['points'],
                        )
                        ->map(fn (array $point): array => [
                            $point['lat'],
                            $point['lng'],
                        ])
                        ->values()
                        ->all();

                    expect($coordinates)->toEqual($expectedCoordinates);

                    return true;
                },
            );

        expect($this->pattern->fresh()->routing_adjustments)->toBe([])
            ->and($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all());

        Http::assertNothingSent();
    })->with([
        'middle point' => 'middle point',
        'last point in segment' => 'last point in segment',
    ]);

    test('moves an adjustment point without changing its order or saving the route', function (): void {
        Http::fake();

        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustment = [
            'from_occurrence_id' => $occurrences[0]->getKey(),
            'to_occurrence_id' => $occurrences[1]->getKey(),
            'points' => [
                ['lat' => 10.415, 'lng' => -84.015],
                ['lat' => 10.435, 'lng' => -84.035],
            ],
        ];

        $expectedAdjustment = array_replace($adjustment, [
            'points' => [
                ['lat' => 10.425, 'lng' => -84.025],
                $adjustment['points'][1],
            ],
        ]);

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.adjustment_mode', true)
            ->set('data.routing_adjustments', [$adjustment])
            ->set('data.calculated_geometry', [
                'type' => 'LineString',
                'coordinates' => [
                    [-84.0, 10.4],
                    [-84.1, 10.5],
                ],
            ])
            ->set('data.distance_meters', 1200)
            ->set('data.driving_duration_seconds', 180)
            ->call(
                'callSchemaComponentMethod',
                'form.route_map',
                'handleAdjustmentPointMove',
                [
                    'layerId' => 'adjustment:'
                        .$adjustment['from_occurrence_id'].':'
                        .$adjustment['to_occurrence_id'].':0',
                    'latitude' => 10.425,
                    'longitude' => -84.025,
                ],
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'routing_adjustments' => [$expectedAdjustment],
                'calculated_geometry' => null,
                'distance_meters' => null,
                'driving_duration_seconds' => null,
            ]);

        expect($this->pattern->fresh()->routing_adjustments)->toBe([])
            ->and($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all());

        Http::assertNothingSent();
    });

    test('rejects an invalid adjustment point move without changing the pending route', function (float $latitude, float $longitude): void {
        Http::fake();

        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustment = [
            'from_occurrence_id' => $occurrences[0]->getKey(),
            'to_occurrence_id' => $occurrences[1]->getKey(),
            'points' => [
                ['lat' => 10.425, 'lng' => -84.025],
            ],
        ];

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0, 10.4],
                [-84.025, 10.425],
                [-84.05, 10.45],
                [-84.1, 10.5],
            ],
        ];

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.adjustment_mode', true)
            ->set('data.routing_adjustments', [$adjustment])
            ->set('data.calculated_geometry', $geometry)
            ->set('data.distance_meters', 1200)
            ->set('data.driving_duration_seconds', 180)
            ->call(
                'callSchemaComponentMethod',
                'form.route_map',
                'handleAdjustmentPointMove',
                [
                    'layerId' => 'adjustment:'
                        .$adjustment['from_occurrence_id'].':'
                        .$adjustment['to_occurrence_id'].':0',
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ],
            )
            ->assertHasNoErrors()
            ->assertNotified('Could Not Move Adjustment Point')
            ->assertSchemaStateSet([
                'routing_adjustments' => [$adjustment],
                'calculated_geometry' => $geometry,
                'distance_meters' => 1200,
                'driving_duration_seconds' => 180,
            ]);

        expect($this->pattern->fresh()->routing_adjustments)->toBe([])
            ->and($this->pattern->fresh()->route_geometry)->toBeNull();

        Http::assertNothingSent();
    })->with([
        'latitude outside bounds' => [91.0, -84.025],
        'longitude outside bounds' => [10.425, -181.0],
    ]);

    test('identifies each adjustment point by its segment and position', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

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
                [
                    'from_occurrence_id' => $occurrences[1]->getKey(),
                    'to_occurrence_id' => $occurrences[2]->getKey(),
                    'points' => [
                        ['lat' => 10.475, 'lng' => -84.075],
                    ],
                ],
            ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $labels = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->filter(
                            fn (array $marker): bool => str_starts_with(
                                $marker['id'],
                                'adjustment:',
                            ),
                        )
                        ->map(
                            fn (array $marker): ?string => data_get(
                                $marker,
                                'tooltip.content',
                            ),
                        )
                        ->values()
                        ->all();

                    expect($labels)->toBe([
                        'Route Adjustment 1: '
                        .$this->originStop->name
                        .' → '.$this->middleStop->name,
                        'Route Adjustment 1: '
                        .$this->middleStop->name
                        .' → '.$this->destinationStop->name,
                    ]);

                    return true;
                },
            );
    });
});
