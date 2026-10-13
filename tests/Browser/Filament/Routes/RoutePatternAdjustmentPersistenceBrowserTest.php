<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Route Pattern Adjustment Persistence Browser Flow', function (): void {
    test('saves a calculated adjustment and restores it when reopening the edit page', function (): void {
        config([
            'app.locale' => 'en',
            'services.osrm.url' => 'https://osrm.test',
        ]);

        app()->setLocale('en');

        $pattern = RoutePattern::factory()->create();
        $route = $pattern->route;
        $company = $route->company;

        $points = [
            [10.4523456, -84.0123456],
            [10.4623456, -84.0223456],
        ];

        $occurrenceIds = [];

        foreach ($points as $index => [$latitude, $longitude]) {
            $stop = Stop::factory()->for($company)->create([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            $occurrenceIds[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 20,
            ])->getKey();
        }

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://osrm.test/route/v1/driving/*' => function (Request $request) {
                    $path = rawurldecode(
                        (string) parse_url($request->url(), PHP_URL_PATH),
                    );

                    $coordinates = substr(
                        $path,
                        strlen('/route/v1/driving/'),
                    );

                    $points = array_map(
                        static fn (string $pair): array => array_map('floatval', explode(',', $pair)),
                        explode(';', $coordinates),
                    );

                    return Http::response([
                        'code' => 'Ok',
                        'routes' => [
                            [
                                'distance' => 1200.5,
                                'duration' => 181.0,
                                'geometry' => [
                                    'type' => 'LineString',
                                    'coordinates' => $points,
                                ],
                            ],
                        ],
                    ]);
                },
            ],
        );

        $path = parse_url(
            RoutePatternResource::getUrl(
                'edit',
                [
                    'route' => $route->getRouteKey(),
                    'record' => $pattern->getRouteKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->assertVisible('.leaflet-container')
            ->click(Selector::getByRoleSelector('switch', [
                'name' => 'Adjust Route',
                'exact' => true,
            ]))
            ->select(
                Selector::getByLabelSelector('Route Segment', false),
                $occurrenceIds[0],
            )
            ->click('.leaflet-container')
            ->assertVisible('.leaflet-marker-icon[data-layer-id^="adjustment:"]')
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Calculate Route',
                'exact' => true,
            ]))
            ->assertSee('Route Calculated')
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '1.20',
            )
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Save changes',
                'exact' => true,
            ]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        $storedPattern = $pattern->fresh();
        $adjustments = $storedPattern->routing_adjustments;

        expect($adjustments)->toHaveCount(1)
            ->and($adjustments[0]['from_occurrence_id'])->toBe($occurrenceIds[0])
            ->and($adjustments[0]['to_occurrence_id'])->toBe($occurrenceIds[1])
            ->and($adjustments[0]['points'])->toHaveCount(1)
            ->and($storedPattern->distance_meters)->toBe('1200.50')
            ->and($storedPattern->driving_duration_seconds)->toBe('181.00')
            ->and($storedPattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($storedPattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 20]);

        $adjustmentPoint = $adjustments[0]['points'][0];

        expect($storedPattern->route_geometry['coordinates'])->toEqual([
            [-84.0123456, 10.4523456],
            [
                round((float) $adjustmentPoint['lng'], 7),
                round((float) $adjustmentPoint['lat'], 7),
            ],
            [-84.0223456, 10.4623456],
        ]);

        $reopenedPage = visit($path)
            ->assertVisible('.leaflet-container')
            ->assertVisible('.leaflet-marker-icon[data-layer-id^="adjustment:"]')
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '1.20',
            )
            ->assertValue(
                Selector::getByLabelSelector('Estimated Driving Time', false),
                '4',
            );

        Execution::instance()->waitForExpectation(function () use ($reopenedPage): void {
            $reopenedPage->assertScript(<<<'JS'
        () => {
            const element = document.querySelector('.leaflet-container');
            const root = element?.closest('[x-data]');
            const component = root ? window.Alpine.$data(root) : null;
            const layers = component?.mapCore?.layers;

            if (!layers) {
                return false;
            }

            const entries = window.Alpine.raw(layers);
            const adjustmentEntry = [...entries.entries()]
                .find(([id]) => id.startsWith('adjustment:'));

            return entries.has('calculated-route')
                && Boolean(adjustmentEntry)
                && !window.Alpine.raw(
                    adjustmentEntry[1].layer
                ).dragging.enabled();
        }
        JS);
        });

        $reopenedPage->assertNoJavaScriptErrors();

        expect(
            Http::recorded(
                fn ($request, $response): bool => parse_url($request->url(), PHP_URL_HOST) === 'osrm.test',
            )->count(),
        )->toBe(1);

        BrowserSession::assertTokenWasValidated();
    });
});
