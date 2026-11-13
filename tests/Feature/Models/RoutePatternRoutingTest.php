<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Validation\ValidationException;

describe('Route Pattern Routing Data', function (): void {
    beforeEach(function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $this->pattern = RoutePattern::factory()->for($route)->create([
            'name' => 'Original Pattern',
        ]);

        $points = [
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ];

        foreach ($points as $index => $point) {
            $stop = Stop::factory()->for($company)->create([
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
            ]);

            RoutePatternStop::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => null,
            ]);
        }

        $this->geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-84.0150000, 10.4550000],
                [-84.0223456, 10.4623456],
            ],
        ];

        $this->pointsHash = hash(
            'sha256',
            implode(';', array_map(
                static fn (array $point): string => sprintf(
                    '%.7F,%.7F',
                    $point['lng'],
                    $point['lat'],
                ),
                $points,
            )),
        );
    });

    test('stores the geometry and its calculated metrics together', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $storedPattern = $this->pattern->fresh();

        expect($storedPattern->route_geometry)->toBe($this->geometry)
            ->and($storedPattern->distance_meters)->toBe('1200.50')
            ->and($storedPattern->driving_duration_seconds)->toBe('180.00')
            ->and($storedPattern->routing_points_hash)->toBe($this->pointsHash);
    });

    test('rejects invalid routing data without saving other pattern changes', function (array $overrides): void {
        $data = array_replace([
            'name' => 'Should Not Persist',
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ], $overrides);

        expect(
            fn () => $this->pattern->update($data),
        )->toThrow(ValidationException::class)
            ->and($this->pattern->fresh()->name)->toBe('Original Pattern');
    })->with([
        'negative distance' => [
            ['distance_meters' => '-1.00'],
        ],
        'negative duration' => [
            ['driving_duration_seconds' => '-1.00'],
        ],
        'missing duration' => [
            ['driving_duration_seconds' => null],
        ],
        'invalid geometry type' => [
            [
                'route_geometry' => [
                    'type' => 'Point',
                    'coordinates' => [-84.0123456, 10.4523456],
                ],
            ],
        ],
        'invalid source hash' => [
            ['routing_points_hash' => 'not-a-hash'],
        ],
    ]);

    test('clears the calculated route when an occurrence changes its stop', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $occurrence = $this->pattern->stopOccurrences()->firstOrFail();

        $replacement = Stop::factory()->create([
            'company_id' => $this->pattern->route->company_id,
            'latitude' => '10.4723456',
            'longitude' => '-84.0323456',
        ]);

        $occurrence->update([
            'stop_id' => $replacement->getKey(),
        ]);

        $pattern = $this->pattern->fresh();

        expect($occurrence->fresh()->stop_id)
            ->toBe($replacement->getKey())
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();
    });

    test('clears the calculated route when an occurrence is added', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $stop = Stop::factory()->create([
            'company_id' => $this->pattern->route->company_id,
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => null,
        ]);

        $pattern = $this->pattern->fresh();

        expect($pattern->stopOccurrences()->count())->toBe(3)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();
    });

    test('clears the calculated route when an occurrence is deleted', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $this->pattern->stopOccurrences()->firstOrFail()->delete();

        $pattern = $this->pattern->fresh();

        expect($pattern->stopOccurrences()->count())->toBe(1)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();
    });

    test('preserves the calculated route when only an arrival estimate changes', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $occurrence = $this->pattern->stopOccurrences()->firstOrFail();

        $occurrence->update([
            'minutes_from_start' => 0,
        ]);

        $pattern = $this->pattern->fresh();

        expect($occurrence->fresh()->minutes_from_start)->toBe(0)
            ->and($pattern->route_geometry)->toEqual($this->geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00')
            ->and($pattern->routing_points_hash)->toBe($this->pointsHash);
    });

    test('clears the calculated route when an occurrence changes position', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $occurrence = $this->pattern->stopOccurrences()->firstOrFail();

        $occurrence->update([
            'stop_sequence' => 3,
        ]);

        $pattern = $this->pattern->fresh();

        expect($occurrence->fresh()->stop_sequence)->toBe(3)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();
    });

    test('clears the calculated route when a stop coordinate changes', function (string $field, string $value): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $occurrence = $this->pattern->stopOccurrences()->firstOrFail();
        $stop = $occurrence->stop;

        $stop->update([
            $field => $value,
        ]);

        $pattern = $this->pattern->fresh();

        expect($stop->fresh()->getAttribute($field))->toBe($value)
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull()
            ->and($occurrence->fresh()->stop_id)->toBe($stop->getKey());
    })->with([
        'latitude changes' => ['latitude', '10.4723456'],
        'longitude changes' => ['longitude', '-84.0323456'],
    ]);

    test('clears routing data for every pattern using a shared stop without affecting unrelated patterns', function (): void {
        $sharedStop = Stop::factory()->create([
            'company_id' => null,
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $firstOccurrence = $this->pattern->stopOccurrences()->firstOrFail();

        $firstOccurrence->update([
            'stop_id' => $sharedStop->getKey(),
        ]);

        $otherCompany = createCompany();
        $otherRoute = Route::factory()->for($otherCompany)->create();
        $otherPattern = RoutePattern::factory()->for($otherRoute)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $sharedStop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => null,
        ]);

        $destination = Stop::factory()->for($otherCompany)->create([
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $destination->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => null,
        ]);

        $unrelatedPattern = RoutePattern::factory()
            ->for($otherRoute)
            ->create();

        foreach ($this->pattern->stopOccurrences()->with('stop')->get() as $occurrence) {
            $privateStop = Stop::factory()->for($otherCompany)->create([
                'latitude' => $occurrence->stop->latitude,
                'longitude' => $occurrence->stop->longitude,
            ]);

            RoutePatternStop::factory()->create([
                'route_pattern_id' => $unrelatedPattern->getKey(),
                'stop_id' => $privateStop->getKey(),
                'stop_sequence' => $occurrence->stop_sequence,
                'minutes_from_start' => null,
            ]);
        }

        $routingData = [
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ];

        foreach ([$this->pattern, $otherPattern, $unrelatedPattern] as $pattern) {
            $pattern->update($routingData);
        }

        $sharedStop->update([
            'latitude' => '10.4723456',
        ]);

        foreach ([$this->pattern, $otherPattern] as $pattern) {
            $storedPattern = $pattern->fresh();

            expect($storedPattern->route_geometry)->toBeNull()
                ->and($storedPattern->distance_meters)->toBeNull()
                ->and($storedPattern->driving_duration_seconds)->toBeNull()
                ->and($storedPattern->routing_points_hash)->toBeNull();
        }

        $unrelatedPattern = $unrelatedPattern->fresh();

        expect($unrelatedPattern->route_geometry)->toEqual($this->geometry)
            ->and($unrelatedPattern->distance_meters)->toBe('1200.50')
            ->and($unrelatedPattern->driving_duration_seconds)->toBe('180.00')
            ->and($unrelatedPattern->routing_points_hash)->toBe($this->pointsHash);
    });

    test('preserves routing data when only a stop name and description change', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $stop = $this->pattern->stopOccurrences()->firstOrFail()->stop;

        $stop->update([
            'name' => 'Updated Boarding Point',
            'description' => 'Board beside the main entrance.',
        ]);

        $pattern = $this->pattern->fresh();
        $stop = $stop->fresh();

        expect($stop->name)->toBe('Updated Boarding Point')
            ->and($stop->description)->toBe('Board beside the main entrance.')
            ->and($pattern->route_geometry)->toEqual($this->geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00')
            ->and($pattern->routing_points_hash)->toBe($this->pointsHash);
    });

    test('stores adjustment points without creating stops or changing their arrival estimates', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustments = [
            [
                'from_occurrence_id' => $occurrences[0]->getKey(),
                'to_occurrence_id' => $occurrences[1]->getKey(),
                'points' => [
                    ['lat' => 10.455, 'lng' => -84.015],
                    ['lat' => 10.458, 'lng' => -84.018],
                ],
            ],
        ];

        $stopsBefore = Stop::withTrashed()->count();
        $occurrenceIds = $occurrences->pluck('id')->all();
        $arrivalEstimates = $occurrences->pluck('minutes_from_start')->all();

        $this->pattern->update([
            'routing_adjustments' => $adjustments,
        ]);

        expect($this->pattern->fresh()->routing_adjustments)
            ->toEqual($adjustments)
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($this->pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($this->pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe($arrivalEstimates);

        $this->pattern->refresh()->update([
            'name' => 'Pattern With Adjustment Points',
        ]);

        expect($this->pattern->fresh()->routing_adjustments)
            ->toEqual($adjustments);
    });

    test('rejects invalid stored adjustments without saving other pattern changes', function (string $scenario): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustment = [
            'from_occurrence_id' => $occurrences[0]->getKey(),
            'to_occurrence_id' => $occurrences[1]->getKey(),
            'points' => [
                ['lat' => 10.455, 'lng' => -84.015],
            ],
        ];

        $adjustments = match ($scenario) {
            'unknown occurrence' => [
                array_replace($adjustment, [
                    'from_occurrence_id' => '00000000-0000-4000-8000-000000000001',
                ]),
            ],
            'reversed segment' => [
                array_replace($adjustment, [
                    'from_occurrence_id' => $occurrences[1]->getKey(),
                    'to_occurrence_id' => $occurrences[0]->getKey(),
                ]),
            ],
            'duplicate segment' => [
                $adjustment,
                $adjustment,
            ],
            'invalid coordinate' => [
                array_replace($adjustment, [
                    'points' => [
                        ['lat' => 91, 'lng' => -84.015],
                    ],
                ]),
            ],
            'missing destination' => [
                array_replace($adjustment, [
                    'to_occurrence_id' => null,
                ]),
            ],
            'unordered point container' => [
                array_replace($adjustment, [
                    'points' => [
                        'first' => ['lat' => 10.455, 'lng' => -84.015],
                    ],
                ]),
            ],
        };

        expect(
            fn () => $this->pattern->update([
                'name' => 'Name That Must Not Be Saved',
                'routing_adjustments' => $adjustments,
            ]),
        )->toThrow(ValidationException::class);

        $pattern = $this->pattern->fresh();

        expect($pattern->name)->toBe('Original Pattern')
            ->and($pattern->routing_adjustments)->toBe([]);
    })->with([
        'unknown occurrence' => 'unknown occurrence',
        'reversed segment' => 'reversed segment',
        'duplicate segment' => 'duplicate segment',
        'invalid coordinate' => 'invalid coordinate',
        'missing destination' => 'missing destination',
        'unordered point container' => 'unordered point container',
    ]);

    test('invalidates routing data only when stored adjustment points change', function (string $scenario): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustment = [
            'from_occurrence_id' => $occurrences[0]->getKey(),
            'to_occurrence_id' => $occurrences[1]->getKey(),
            'points' => [
                ['lat' => 10.455, 'lng' => -84.015],
            ],
        ];

        $originalAdjustments = $scenario === 'added'
            ? []
            : [$adjustment];

        $this->pattern->update([
            'routing_adjustments' => $originalAdjustments,
        ]);

        $coordinates = $scenario === 'added'
            ? '-84.0123456,10.4523456;-84.0223456,10.4623456'
            : '-84.0123456,10.4523456;'
            .'-84.0150000,10.4550000;'
            .'-84.0223456,10.4623456';

        $originalHash = hash('sha256', $coordinates);

        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $originalHash,
        ]);

        $newAdjustments = match ($scenario) {
            'added' => [$adjustment],
            'changed' => [
                array_replace($adjustment, [
                    'points' => [
                        ['lat' => 10.458, 'lng' => -84.018],
                    ],
                ]),
            ],
            'removed' => [],
            'unchanged' => $originalAdjustments,
        };

        $this->pattern->update([
            'routing_adjustments' => $newAdjustments,
        ]);

        $pattern = $this->pattern->fresh();
        $unchanged = $scenario === 'unchanged';

        expect($pattern->routing_adjustments)->toEqual($newAdjustments)
            ->and($pattern->route_geometry)
            ->toEqual($unchanged ? $this->geometry : null)
            ->and($pattern->distance_meters)
            ->toBe($unchanged ? '1200.50' : null)
            ->and($pattern->driving_duration_seconds)
            ->toBe($unchanged ? '180.00' : null)
            ->and($pattern->routing_points_hash)
            ->toBe($unchanged ? $originalHash : null)
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrences->pluck('id')->all());
    })->with([
        'adjustment added' => 'added',
        'adjustment changed' => 'changed',
        'adjustment removed' => 'removed',
        'adjustments unchanged' => 'unchanged',
    ]);

    test('removes adjustments referencing a deleted occurrence while preserving unaffected segments', function (): void {
        $thirdStop = Stop::factory()->create([
            'company_id' => $this->pattern->route->company_id,
            'latitude' => '10.4723456',
            'longitude' => '-84.0323456',
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'stop_id' => $thirdStop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => null,
        ]);

        $occurrences = $this->pattern->stopOccurrences()->get();

        $adjustments = [
            [
                'from_occurrence_id' => $occurrences[0]->getKey(),
                'to_occurrence_id' => $occurrences[1]->getKey(),
                'points' => [
                    ['lat' => 10.455, 'lng' => -84.015],
                ],
            ],
            [
                'from_occurrence_id' => $occurrences[1]->getKey(),
                'to_occurrence_id' => $occurrences[2]->getKey(),
                'points' => [
                    ['lat' => 10.467, 'lng' => -84.027],
                ],
            ],
        ];

        $this->pattern->update([
            'routing_adjustments' => $adjustments,
        ]);

        $stopsBefore = Stop::withTrashed()->count();

        $occurrences[0]->delete();

        $pattern = $this->pattern->fresh();

        expect($pattern->routing_adjustments)
            ->toEqual([$adjustments[1]])
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe([
                $occurrences[1]->getKey(),
                $occurrences[2]->getKey(),
            ])
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore);
    });

    test('restores the occurrence and routing data when invalidation fails during deletion', function (): void {
        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $this->pointsHash,
        ]);

        $occurrence = $this->pattern->stopOccurrences()->firstOrFail();
        $occurrenceIds = $this->pattern->stopOccurrences()->pluck('id')->all();
        $patternId = $this->pattern->getKey();

        $originalDispatcher = RoutePattern::getEventDispatcher();

        RoutePattern::setEventDispatcher(clone $originalDispatcher);

        try {
            RoutePattern::updated(
                static function (RoutePattern $pattern) use ($patternId): void {
                    if (
                        $pattern->getKey() === $patternId
                        && $pattern->wasChanged('routing_points_hash')
                        && $pattern->routing_points_hash === null
                    ) {
                        throw new RuntimeException(
                            'Routing invalidation failed.',
                        );
                    }
                },
            );

            expect(
                fn () => $occurrence->delete(),
            )->toThrow(
                RuntimeException::class,
                'Routing invalidation failed.',
            );
        } finally {
            RoutePattern::setEventDispatcher($originalDispatcher);
        }

        $pattern = $this->pattern->fresh();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $patternId,
            'stop_id' => $occurrence->stop_id,
            'stop_sequence' => $occurrence->stop_sequence,
        ]);

        expect($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($pattern->route_geometry)->toEqual($this->geometry)
            ->and($pattern->distance_meters)->toBe('1200.50')
            ->and($pattern->driving_duration_seconds)->toBe('180.00')
            ->and($pattern->routing_points_hash)->toBe($this->pointsHash);
    });

    test('removes adjustments for a segment split by a newly inserted stop occurrence', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $occurrences[1]->update([
            'stop_sequence' => 3,
        ]);

        $this->pattern->update([
            'routing_adjustments' => [
                [
                    'from_occurrence_id' => $occurrences[0]->getKey(),
                    'to_occurrence_id' => $occurrences[1]->getKey(),
                    'points' => [
                        ['lat' => 10.455, 'lng' => -84.015],
                    ],
                ],
            ],
        ]);

        $this->pattern->update([
            'route_geometry' => $this->geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => hash(
                'sha256',
                '-84.0123456,10.4523456;'
                .'-84.0150000,10.4550000;'
                .'-84.0223456,10.4623456',
            ),
        ]);

        $middleStop = Stop::factory()->create([
            'company_id' => $this->pattern->route->company_id,
            'latitude' => '10.4573456',
            'longitude' => '-84.0173456',
        ]);

        $middleOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'stop_id' => $middleStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => null,
        ]);

        $pattern = $this->pattern->fresh();

        expect($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe([
                $occurrences[0]->getKey(),
                $middleOccurrence->getKey(),
                $occurrences[1]->getKey(),
            ])
            ->and($pattern->routing_adjustments)->toBe([])
            ->and($pattern->route_geometry)->toBeNull()
            ->and($pattern->distance_meters)->toBeNull()
            ->and($pattern->driving_duration_seconds)->toBeNull()
            ->and($pattern->routing_points_hash)->toBeNull();
    });
});
