<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Tests\Support\BrowserSession;

describe('Route Pattern View Map Browser Flow', function (): void {
    test('displays saved route geometry and stop markers without calculating again', function (): void {
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

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-84.0150000, 10.4550000],
                [-84.0223456, 10.4623456],
            ],
        ];

        $pattern->update([
            'route_geometry' => $geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '181.00',
            'routing_points_hash' => hash(
                'sha256',
                '-84.0123456,10.4523456;-84.0223456,10.4623456',
            ),
        ]);

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
        );

        $path = parse_url(
            RoutePatternResource::getUrl(
                'view',
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
            ->assertSee('Total Distance')
            ->assertSee('1.20')
            ->assertSee('Estimated Driving Time')
            ->assertSee('4 min');

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                () => {
                    const element = document.querySelector('.leaflet-container');
                    const root = element?.closest('[x-data]');
                    const component = root ? window.Alpine.$data(root) : null;
                    const layers = component?.mapCore?.layers;

                    if (!layers) {
                        return false;
                    }

                    const entries = window.Alpine.raw(layers);
                    const route = entries.get('calculated-route');

                    if (!route) {
                        return false;
                    }

                    const expectedRoute = [
                        [10.4523456, -84.0123456],
                        [10.455, -84.015],
                        [10.4623456, -84.0223456],
                    ];

                    const routePoints = window.Alpine.raw(route.layer).getLatLngs();

                    const routeMatches = routePoints.length === expectedRoute.length
                        && routePoints.every((point, index) =>
                            Math.abs(point.lat - expectedRoute[index][0]) < 0.0000001
                            && Math.abs(point.lng - expectedRoute[index][1]) < 0.0000001
                        );

                    const markers = [...entries.entries()]
                        .filter(([id]) => id.startsWith('stop-'))
                        .map(([, entry]) => window.Alpine.raw(entry.layer).getLatLng());

                    const expectedStops = [
                        [10.4523456, -84.0123456],
                        [10.4623456, -84.0223456],
                    ];

                    const stopsMatch = markers.length === expectedStops.length
                        && expectedStops.every(([lat, lng]) =>
                            markers.some(point =>
                                Math.abs(point.lat - lat) < 0.0000001
                                && Math.abs(point.lng - lng) < 0.0000001
                            )
                        );

                    return routeMatches && stopsMatch;
                }
                JS);
        });

        $page->assertNoJavaScriptErrors();

        expect($pattern->fresh()->route_geometry)->toEqual($geometry)
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 20]);

        Http::assertNotSent(
            fn ($request): bool => parse_url($request->url(), PHP_URL_HOST) === 'osrm.test',
        );

        BrowserSession::assertTokenWasValidated();
    });
});
