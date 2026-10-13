<?php

use App\Services\RouteRoutingPointsService;

describe('Route Routing Points Assembly', function (): void {
    beforeEach(function (): void {
        $this->stops = [
            [
                'occurrence_id' => 'origin-occurrence',
                'lat' => 10.4,
                'lng' => -84.0,
            ],
            [
                'occurrence_id' => 'middle-occurrence',
                'lat' => 10.45,
                'lng' => -84.05,
            ],
            [
                'occurrence_id' => 'destination-occurrence',
                'lat' => 10.5,
                'lng' => -84.1,
            ],
        ];
    });

    test('preserves every stop in travel order when no adjustments exist', function (): void {
        $points = (new RouteRoutingPointsService)->build(
            $this->stops,
            [],
        );

        expect($points)->toBe([
            ['lat' => 10.4, 'lng' => -84.0],
            ['lat' => 10.45, 'lng' => -84.05],
            ['lat' => 10.5, 'lng' => -84.1],
        ]);
    });

    test('inserts adjustment points within their segments while preserving their order', function (): void {
        $adjustments = [
            [
                'from_occurrence_id' => 'middle-occurrence',
                'to_occurrence_id' => 'destination-occurrence',
                'points' => [
                    ['lat' => 10.47, 'lng' => -84.07],
                ],
            ],
            [
                'from_occurrence_id' => 'origin-occurrence',
                'to_occurrence_id' => 'middle-occurrence',
                'points' => [
                    ['lat' => 10.41, 'lng' => -84.01],
                    ['lat' => 10.42, 'lng' => -84.02],
                ],
            ],
        ];

        $points = (new RouteRoutingPointsService)->build(
            $this->stops,
            $adjustments,
        );

        expect($points)->toBe([
            ['lat' => 10.4, 'lng' => -84.0],
            ['lat' => 10.41, 'lng' => -84.01],
            ['lat' => 10.42, 'lng' => -84.02],
            ['lat' => 10.45, 'lng' => -84.05],
            ['lat' => 10.47, 'lng' => -84.07],
            ['lat' => 10.5, 'lng' => -84.1],
        ]);
    });

    test('rejects adjustments that do not identify a unique consecutive segment', function (string $scenario): void {
        $adjustment = [
            'from_occurrence_id' => 'origin-occurrence',
            'to_occurrence_id' => 'middle-occurrence',
            'points' => [
                ['lat' => 10.41, 'lng' => -84.01],
            ],
        ];

        $adjustments = match ($scenario) {
            'unknown origin' => [
                array_replace($adjustment, [
                    'from_occurrence_id' => 'foreign-occurrence',
                ]),
            ],
            'unknown destination' => [
                array_replace($adjustment, [
                    'to_occurrence_id' => 'foreign-occurrence',
                ]),
            ],
            'reversed segment' => [
                array_replace($adjustment, [
                    'from_occurrence_id' => 'middle-occurrence',
                    'to_occurrence_id' => 'origin-occurrence',
                ]),
            ],
            'nonconsecutive segment' => [
                array_replace($adjustment, [
                    'to_occurrence_id' => 'destination-occurrence',
                ]),
            ],
            'duplicate segment' => [
                $adjustment,
                $adjustment,
            ],
        };

        expect(
            fn () => (new RouteRoutingPointsService)->build(
                $this->stops,
                $adjustments,
            ),
        )->toThrow(InvalidArgumentException::class);
    })->with([
        'unknown origin' => 'unknown origin',
        'unknown destination' => 'unknown destination',
        'reversed segment' => 'reversed segment',
        'nonconsecutive segment' => 'nonconsecutive segment',
        'duplicate segment' => 'duplicate segment',
    ]);

    test('rejects invalid coordinates before assembling routing points', function (string $field, mixed $value, string $source): void {
        $stops = $this->stops;

        $adjustments = [
            [
                'from_occurrence_id' => 'origin-occurrence',
                'to_occurrence_id' => 'middle-occurrence',
                'points' => [
                    ['lat' => 10.41, 'lng' => -84.01],
                ],
            ],
        ];

        if ($source === 'stop') {
            $stops[0][$field] = $value;
        } else {
            $adjustments[0]['points'][0][$field] = $value;
        }

        expect(
            fn () => (new RouteRoutingPointsService)->build(
                $stops,
                $adjustments,
            ),
        )->toThrow(InvalidArgumentException::class);
    })->with([
        'latitude above range' => ['lat', 91],
        'latitude below range' => ['lat', -91],
        'longitude above range' => ['lng', 181],
        'longitude below range' => ['lng', -181],
        'nonnumeric coordinate' => ['lat', 'invalid'],
        'missing coordinate value' => ['lng', null],
        'infinite coordinate' => ['lat', INF],
        'not a number' => ['lng', NAN],
    ])->with([
        'stop coordinate' => 'stop',
        'adjustment coordinate' => 'adjustment',
    ]);

    test('accepts exactly one hundred routing points including stops and adjustments', function (): void {
        $adjustments = [
            [
                'from_occurrence_id' => 'origin-occurrence',
                'to_occurrence_id' => 'middle-occurrence',
                'points' => array_fill(
                    0,
                    97,
                    ['lat' => 10.41, 'lng' => -84.01],
                ),
            ],
        ];

        $points = (new RouteRoutingPointsService)->build(
            $this->stops,
            $adjustments,
        );

        expect($points)->toHaveCount(100)
            ->and($points[0])->toBe([
                'lat' => 10.4,
                'lng' => -84.0,
            ])
            ->and($points[98])->toBe([
                'lat' => 10.45,
                'lng' => -84.05,
            ])
            ->and($points[99])->toBe([
                'lat' => 10.5,
                'lng' => -84.1,
            ]);
    });

    test('rejects more than one hundred routing points including stops and adjustments', function (): void {
        $adjustments = [
            [
                'from_occurrence_id' => 'origin-occurrence',
                'to_occurrence_id' => 'middle-occurrence',
                'points' => array_fill(
                    0,
                    98,
                    ['lat' => 10.41, 'lng' => -84.01],
                ),
            ],
        ];

        expect(
            fn () => (new RouteRoutingPointsService)->build(
                $this->stops,
                $adjustments,
            ),
        )->toThrow(InvalidArgumentException::class);
    });

    test('rejects stop lists without enough uniquely identified occurrences', function (string $scenario): void {
        $stops = $this->stops;

        switch ($scenario) {
            case 'empty list':
                $stops = [];
                break;

            case 'single occurrence':
                $stops = [$stops[0]];
                break;

            case 'duplicate occurrence':
                $stops[1]['occurrence_id'] = $stops[0]['occurrence_id'];
                break;

            case 'missing occurrence identifier':
                unset($stops[0]['occurrence_id']);
                break;

            case 'blank occurrence identifier':
                $stops[0]['occurrence_id'] = ' ';
                break;
        }

        expect(
            fn () => (new RouteRoutingPointsService)->build(
                $stops,
                [],
            ),
        )->toThrow(InvalidArgumentException::class);
    })->with([
        'empty list' => 'empty list',
        'single occurrence' => 'single occurrence',
        'duplicate occurrence' => 'duplicate occurrence',
        'missing occurrence identifier' => 'missing occurrence identifier',
        'blank occurrence identifier' => 'blank occurrence identifier',
    ]);
});
