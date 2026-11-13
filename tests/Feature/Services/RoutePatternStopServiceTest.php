<?php

use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Services\RoutePatternStopService;
use Illuminate\Validation\ValidationException;

describe('Route Pattern Stop Reordering', function (): void {
    test('reorders repeated appearances and clears their previous estimates', function (): void {
        $pattern = RoutePattern::factory()->create();
        $company = $pattern->route->company;

        $firstStop = Stop::factory()->for($company)->create();
        $secondStop = Stop::factory()->for($company)->create();

        $occurrences = [];

        foreach ([
            [$firstStop, 0],
            [$secondStop, 10],
            [$firstStop, 20],
        ] as $index => [$stop, $minutes]) {
            $occurrences[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $minutes,
            ]);
        }

        $orderedIds = [
            $occurrences[2]->getKey(),
            $occurrences[0]->getKey(),
            $occurrences[1]->getKey(),
        ];

        app(RoutePatternStopService::class)->reorder(
            $pattern,
            $orderedIds,
        );

        $storedOccurrences = $pattern->stopOccurrences()->get();

        expect($storedOccurrences->pluck('id')->all())->toBe($orderedIds)
            ->and($storedOccurrences->pluck('stop_sequence')->all())
            ->toBe([1, 2, 3])
            ->and($storedOccurrences->pluck('minutes_from_start')->all())
            ->toBe([null, null, null])
            ->and($storedOccurrences->pluck('stop_id')->all())
            ->toBe([
                $firstStop->getKey(),
                $firstStop->getKey(),
                $secondStop->getKey(),
            ]);
    });

    test('rejects invalid occurrence lists without changing existing values', function (string $scenario): void {
        $pattern = RoutePattern::factory()->create();
        $company = $pattern->route->company;

        $otherPattern = RoutePattern::factory()
            ->for($pattern->route)
            ->create();

        $stop = Stop::factory()->for($company)->create();

        $first = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $second = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        $foreign = RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $orderedIds = match ($scenario) {
            'missing' => [$first->getKey()],
            'duplicate' => [$first->getKey(), $first->getKey()],
            'foreign' => [$first->getKey(), $foreign->getKey()],
        };

        expect(fn () => app(RoutePatternStopService::class)->reorder(
            $pattern,
            $orderedIds,
        ))->toThrow(ValidationException::class);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $first->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $second->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $foreign->getKey(),
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
    })->with([
        'incomplete list' => 'missing',
        'duplicate occurrence' => 'duplicate',
        'another pattern occurrence' => 'foreign',
    ]);

    test('preserves estimates when the requested order is unchanged', function (): void {
        $pattern = RoutePattern::factory()->create();
        $stop = Stop::factory()->for($pattern->route->company)->create();

        $occurrences = [];

        foreach ([0, 10] as $index => $minutes) {
            $occurrences[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $minutes,
            ]);
        }

        $orderedIds = array_map(
            fn (RoutePatternStop $occurrence): string => $occurrence->getKey(),
            $occurrences,
        );

        app(RoutePatternStopService::class)->reorder(
            $pattern,
            $orderedIds,
        );

        expect($pattern->stopOccurrences()
            ->pluck('minutes_from_start')
            ->all())->toBe([0, 10]);
    });

    test('rejects reordering when the pattern or its route is archived', function (string $reference): void {
        $pattern = RoutePattern::factory()->create();
        $stop = Stop::factory()->for($pattern->route->company)->create();

        $first = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $second = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        match ($reference) {
            'pattern' => $pattern->delete(),
            'route' => $pattern->route->delete(),
        };

        expect(fn () => app(RoutePatternStopService::class)->reorder(
            $pattern,
            [$second->getKey(), $first->getKey()],
        ))->toThrow(ValidationException::class);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $first->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $second->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);
    })->with([
        'archived pattern' => 'pattern',
        'archived route' => 'route',
    ]);

    test('preserves the entire sequence when an archived stop rejects reordering', function (): void {
        $pattern = RoutePattern::factory()->create();
        $company = $pattern->route->company;

        $firstStop = Stop::factory()->for($company)->create();
        $secondStop = Stop::factory()->for($company)->create();

        $first = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $firstStop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $second = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $secondStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        // Simulate legacy data containing an archived stop still referenced by a pattern.
        $secondStop->deleteQuietly();

        expect(fn () => app(RoutePatternStopService::class)->reorder(
            $pattern,
            [$second->getKey(), $first->getKey()],
        ))->toThrow(ValidationException::class);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $first->getKey(),
            'stop_id' => $firstStop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $second->getKey(),
            'stop_id' => $secondStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        $this->assertSoftDeleted($secondStop);
    });

    test('invalidates routing data only when the requested order changes', function (bool $reverse): void {
        $pattern = RoutePattern::factory()->create();
        $company = $pattern->route->company;

        $points = [
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ];

        $occurrences = [];

        foreach ($points as $index => $point) {
            $stop = Stop::factory()->for($company)->create([
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
            ]);

            $occurrences[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 10,
            ]);
        }

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-84.0223456, 10.4623456],
            ],
        ];

        $pointsHash = hash(
            'sha256',
            '-84.0123456,10.4523456;-84.0223456,10.4623456',
        );

        $pattern->update([
            'route_geometry' => $geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => $pointsHash,
        ]);

        $orderedIds = array_map(
            fn (RoutePatternStop $occurrence): string => $occurrence->getKey(),
            $occurrences,
        );

        if ($reverse) {
            $orderedIds = array_reverse($orderedIds);
        }

        app(RoutePatternStopService::class)->reorder(
            $pattern,
            $orderedIds,
        );

        $storedPattern = $pattern->fresh();

        expect($storedPattern->stopOccurrences()->pluck('id')->all())
            ->toBe($orderedIds)
            ->and($storedPattern->route_geometry)
            ->toEqual($reverse ? null : $geometry)
            ->and($storedPattern->distance_meters)
            ->toBe($reverse ? null : '1200.50')
            ->and($storedPattern->driving_duration_seconds)
            ->toBe($reverse ? null : '180.00')
            ->and($storedPattern->routing_points_hash)
            ->toBe($reverse ? null : $pointsHash)
            ->and($storedPattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe($reverse ? [null, null] : [0, 10]);
    })->with([
        'changed order' => true,
        'unchanged order' => false,
    ]);

    test('removes obsolete adjustments after reordering while preserving consecutive segments', function (): void {
        $pattern = RoutePattern::factory()->create();
        $company = $pattern->route->company;

        $coordinates = [
            [10.4, -84.0],
            [10.42, -84.02],
            [10.44, -84.04],
            [10.46, -84.06],
        ];

        $occurrences = [];

        foreach ($coordinates as $index => [$latitude, $longitude]) {
            $stop = Stop::factory()->for($company)->create([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            $occurrences[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 10,
            ]);
        }

        $adjustments = [
            [
                'from_occurrence_id' => $occurrences[0]->getKey(),
                'to_occurrence_id' => $occurrences[1]->getKey(),
                'points' => [
                    ['lat' => 10.41, 'lng' => -84.01],
                ],
            ],
            [
                'from_occurrence_id' => $occurrences[1]->getKey(),
                'to_occurrence_id' => $occurrences[2]->getKey(),
                'points' => [
                    ['lat' => 10.43, 'lng' => -84.03],
                ],
            ],
            [
                'from_occurrence_id' => $occurrences[2]->getKey(),
                'to_occurrence_id' => $occurrences[3]->getKey(),
                'points' => [
                    ['lat' => 10.45, 'lng' => -84.05],
                ],
            ],
        ];

        $pattern->update([
            'routing_adjustments' => $adjustments,
        ]);

        $orderedIds = [
            $occurrences[2]->getKey(),
            $occurrences[3]->getKey(),
            $occurrences[0]->getKey(),
            $occurrences[1]->getKey(),
        ];

        app(RoutePatternStopService::class)->reorder(
            $pattern,
            $orderedIds,
        );

        $storedPattern = $pattern->fresh();

        expect($storedPattern->stopOccurrences()->pluck('id')->all())
            ->toBe($orderedIds)
            ->and($storedPattern->routing_adjustments)
            ->toEqual([
                $adjustments[2],
                $adjustments[0],
            ])
            ->and($storedPattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([null, null, null, null])
            ->and(Stop::query()->where('company_id', $company->getKey())->count())
            ->toBe(4);
    });
});
