<?php

use App\Services\RouteDetourAnalyzer;

describe('Route Detour Analyzer', function (): void {
    beforeEach(function (): void {
        config([
            'services.osrm.detour.minimum_ratio' => 3,
            'services.osrm.detour.minimum_excess_meters' => 10000,
        ]);
    });

    test('flags the considerable detour between Quique and Cruce Río Frío', function (): void {
        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            points: [
                ['lat' => 10.337504, 'lng' => -83.9520581],
                ['lat' => 10.2112711, 'lng' => -83.8985962],
            ],
            legDistances: [79309.5],
            segments: [
                ['from_point_index' => 0, 'to_point_index' => 1],
            ],
        );

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toMatchArray([
                'segment_index' => 0,
                'distance_meters' => 79309.5,
            ]);
    });

    test('does not flag a normal road route', function (): void {
        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            points: [
                ['lat' => 10.337504, 'lng' => -83.9520581],
                ['lat' => 10.2112711, 'lng' => -83.8985962],
            ],
            legDistances: [16297.9],
            segments: [
                ['from_point_index' => 0, 'to_point_index' => 1],
            ],
        );

        expect($warnings)->toBeEmpty();
    });

    test('does not flag short detours below the minimum excess', function (): void {
        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            points: [
                ['lat' => 0, 'lng' => 0],
                ['lat' => 0, 'lng' => 0.001],
            ],
            legDistances: [1000.0],
            segments: [
                ['from_point_index' => 0, 'to_point_index' => 1],
            ],
        );

        expect($warnings)->toBeEmpty();
    });

    test('includes adjustment points in the comparison for a stop segment', function (): void {
        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            points: [
                ['lat' => 0, 'lng' => 0],
                ['lat' => 1, 'lng' => 0],
                ['lat' => 0, 'lng' => 0.2],
            ],
            legDistances: [125000.0, 125000.0],
            segments: [
                ['from_point_index' => 0, 'to_point_index' => 2],
            ],
        );

        expect($warnings)->toBeEmpty();
    });

    test('groups adjustment legs and identifies only the suspicious stop segment', function (): void {
        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            points: [
                ['lat' => 0, 'lng' => 0],
                ['lat' => 0, 'lng' => 0.1],
                ['lat' => 0, 'lng' => 0.2],
                ['lat' => 0, 'lng' => 0.3],
            ],
            legDistances: [40000.0, 40000.0, 12000.0],
            segments: [
                ['from_point_index' => 0, 'to_point_index' => 2],
                ['from_point_index' => 2, 'to_point_index' => 3],
            ],
        );

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toMatchArray([
                'segment_index' => 0,
                'distance_meters' => 80000.0,
            ]);
    });
});
