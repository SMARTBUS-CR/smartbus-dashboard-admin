<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Route Pattern Calculation Browser Flow', function (): void {
    test('draws the calculated route and clears the proposal when the origin changes', function (): void {
        config([
            'app.locale' => 'en',
            'services.osrm.url' => 'https://osrm.test',
            'services.photon.url' => 'https://photon.test',
        ]);

        app()->setLocale('en');

        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $origin = Stop::factory()->for($company)->create([
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $destination = Stop::factory()->for($company)->create([
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        foreach ([$origin, $destination] as $index => $stop) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 20,
            ]);
        }

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://osrm.test/route/v1/driving/*' => Http::response([
                    'code' => 'Ok',
                    'routes' => [
                        [
                            'distance' => 1200.5,
                            'duration' => 180.0,
                            'geometry' => [
                                'type' => 'LineString',
                                'coordinates' => [
                                    [-84.0123456, 10.4523456],
                                    [-84.0150000, 10.4550000],
                                    [-84.0223456, 10.4623456],
                                ],
                            ],
                        ],
                    ],
                ]),
                'https://photon.test/api/*' => Http::response([
                    'type' => 'FeatureCollection',
                    'features' => [
                        [
                            'type' => 'Feature',
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [-84.0323456, 10.4723456],
                            ],
                            'properties' => [
                                'name' => 'Alternate Terminal',
                                'country' => 'Costa Rica',
                                'countrycode' => 'CR',
                            ],
                        ],
                    ],
                ]),
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
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Calculate Route',
                'exact' => true,
            ]))
            ->assertSee('Route Calculated')
            ->assertSee('Total Distance')
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '1.20',
            )
            ->assertSee('Estimated Driving Time');

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

                    const entry = window.Alpine.raw(layers).get('calculated-route');

                    if (!entry) {
                        return false;
                    }

                    const points = window.Alpine.raw(entry.layer).getLatLngs();
                    const expected = [
                        [10.4523456, -84.0123456],
                        [10.455, -84.015],
                        [10.4623456, -84.0223456],
                    ];

                    return points.length === expected.length
                        && points.every((point, index) =>
                            Math.abs(point.lat - expected[index][0]) < 0.0000001
                            && Math.abs(point.lng - expected[index][1]) < 0.0000001
                        );
                }
                JS);
        });

        $page
            ->click('[data-testid="origin_search"] [role="combobox"]')
            ->type(
                '[data-testid="origin_search"] input[aria-label="Search"]',
                'Alternate Terminal',
            )
            ->assertSee('Alternate Terminal, Costa Rica')
            ->click(Selector::getByRoleSelector('option', [
                'name' => 'Alternate Terminal, Costa Rica',
                'exact' => true,
            ]))
            ->assertAttribute(
                Selector::getByLabelSelector('Total Distance', false),
                'placeholder',
                'Not Calculated',
            )
            ->assertAttribute(
                Selector::getByLabelSelector('Estimated Driving Time', false),
                'placeholder',
                'Not Calculated',
            )
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '',
            )
            ->assertValue(
                Selector::getByLabelSelector('Estimated Driving Time', false),
                '',
            );

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                () => {
                    const element = document.querySelector('.leaflet-container');
                    const root = element?.closest('[x-data]');
                    const component = root ? window.Alpine.$data(root) : null;
                    const layers = component?.mapCore?.layers;

                    return Boolean(layers)
                        && !window.Alpine.raw(layers).has('calculated-route');
                }
                JS);
        });

        $page->assertNoJavaScriptErrors();

        expect($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe([$origin->getKey(), $destination->getKey()])
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 20])
            ->and(Http::recorded(
                fn ($request, $response): bool => parse_url(
                    $request->url(),
                    PHP_URL_HOST,
                ) === 'osrm.test',
            )->count())->toBe(1);

        BrowserSession::assertTokenWasValidated();
    });
});
