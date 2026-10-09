<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Services\RoutePatternStopService;

describe('Route Pattern Routing Leg Distances', function (): void {
    beforeEach(function (): void {
        $company = Company::factory()->create();
        $route = Route::factory()->for($company)->create();

        $this->pattern = RoutePattern::factory()->for($route)->create();

        $this->stops = [
            Stop::factory()->for($company)->create([
                'latitude' => '10.4000000',
                'longitude' => '-84.0000000',
            ]),
            Stop::factory()->for($company)->create([
                'latitude' => '10.5000000',
                'longitude' => '-84.1000000',
            ]),
        ];

        $this->occurrences = [];

        foreach ($this->stops as $index => $stop) {
            $this->occurrences[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 15,
            ]);
        }

        $this->calculation = [
            'route_geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [-84.0, 10.4],
                    [-84.1, 10.5],
                ],
            ],
            'distance_meters' => 1200.5,
            'driving_duration_seconds' => 180.0,
            'routing_points_hash' => hash('sha256', 'routing-test'),
            'routing_leg_distances' => [1200.5],
        ];
    });

    test('persists the leg distances with the calculated route', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);
    });

    test('clears leg distances when the calculated route is invalidated', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);

        $this->pattern->update([
            'route_geometry' => null,
            'distance_meters' => null,
            'driving_duration_seconds' => null,
            'routing_points_hash' => null,
        ]);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBeNull();
    });

    test('preserves leg distances when only descriptive information changes', function (): void {
        $this->pattern->update($this->calculation);

        $this->pattern->update([
            'name' => 'Updated Pattern Name',
        ]);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);
    });

    test('clears persisted leg distances when a stop occurrence is removed', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);

        $this->occurrences[1]->delete();

        $pattern = $this->pattern->fresh();

        expect($pattern->routing_leg_distances)->toBeNull()
            ->and($pattern->route_geometry)->toBeNull();
    });

    test('clears persisted leg distances when a stop location changes', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);

        $this->stops[1]->update([
            'latitude' => '10.5100000',
        ]);

        $pattern = $this->pattern->fresh();

        expect($pattern->routing_leg_distances)->toBeNull()
            ->and($pattern->route_geometry)->toBeNull();
    });

    test('clears persisted leg distances when stop occurrences are reordered', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);

        $orderedIds = [
            $this->occurrences[1]->getKey(),
            $this->occurrences[0]->getKey(),
        ];

        app(RoutePatternStopService::class)->reorder(
            $this->pattern,
            $orderedIds,
        );

        $pattern = $this->pattern->fresh();

        expect($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($orderedIds)
            ->and($pattern->routing_leg_distances)->toBeNull()
            ->and($pattern->route_geometry)->toBeNull();
    });

    test('preserves persisted leg distances when the stop order does not change', function (): void {
        $this->pattern->update($this->calculation);

        expect($this->pattern->fresh()->routing_leg_distances)
            ->toBe([1200.5]);

        app(RoutePatternStopService::class)->reorder(
            $this->pattern,
            [
                $this->occurrences[0]->getKey(),
                $this->occurrences[1]->getKey(),
            ],
        );

        $pattern = $this->pattern->fresh();

        expect($pattern->routing_leg_distances)->toBe([1200.5])
            ->and($pattern->route_geometry)->not->toBeNull();
    });
});
