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

describe('Route Pattern Detour Warning Browser Flow', function (): void {
    test('preserves the warning after saving and reopening and clears it when the origin changes', function (): void {
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
            'name' => 'Origin Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $destination = Stop::factory()->for($company)->create([
            'name' => 'Destination Terminal',
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

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-83.88, 10.4523456],
                [-83.88, 10.4623456],
                [-84.0223456, 10.4623456],
            ],
        ];

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://osrm.test/route/v1/driving/*' => Http::response([
                    'code' => 'Ok',
                    'routes' => [
                        [
                            'distance' => 30000.0,
                            'duration' => 1800.0,
                            'geometry' => $geometry,
                            'legs' => [
                                ['distance' => 30000.0],
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

        $warning = 'The segment from Origin Terminal to Destination Terminal '
            .'has a calculated distance of 30.00 km '
            .'and may include a considerable detour.';

        $page = visit($path)
            ->assertVisible('.leaflet-container')
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Calculate Route',
                'exact' => true,
            ]))
            ->assertSee('Review the calculated route')
            ->assertSee($warning)
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '30.00',
            );

        $page->click(Selector::getByRoleSelector('button', [
            'name' => 'Locate segment',
            'exact' => true,
        ]));

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                    () => {
                        const element = document.querySelector('.leaflet-container');
                        const root = element?.closest('[x-data]');
                        const component = root ? window.Alpine.$data(root) : null;
                        const layers = component?.mapCore?.layers;
                        const map = component?.mapCore?.map;

                        if (!layers || !map) {
                            return false;
                        }

                        const entries = window.Alpine.raw(layers);
                        const origin = entries.get('origin');
                        const destination = entries.get('destination');

                        if (!origin || !destination) {
                            return false;
                        }

                        const originLayer = window.Alpine.raw(origin.layer);
                        const destinationLayer = window.Alpine.raw(destination.layer);

                        return originLayer.getElement()?.dataset.detourFocus === 'true'
                            && destinationLayer.getElement()?.dataset.detourFocus === 'true'
                            && map.getBounds().contains(originLayer.getLatLng())
                            && map.getBounds().contains(destinationLayer.getLatLng())
                            && entries.has('calculated-route');
                    }
                    JS);
        });

        $page->click(Selector::getByRoleSelector('button', [
            'name' => 'Save changes',
            'exact' => true,
        ]));

        Execution::instance()->waitForExpectation(function () use ($pattern): void {
            expect($pattern->fresh()->routing_leg_distances)
                ->toEqual([30000.0]);
        });

        $page = visit($path)
            ->assertVisible('.leaflet-container')
            ->assertSee('Review the calculated route')
            ->assertSee($warning);

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                () => {
                    const element = document.querySelector('.leaflet-container');
                    const root = element?.closest('[x-data]');
                    const component = root ? window.Alpine.$data(root) : null;
                    const layers = component?.mapCore?.layers;

                    return Boolean(layers)
                        && window.Alpine.raw(layers).has('calculated-route');
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
            ->assertValue(
                Selector::getByLabelSelector('Total Distance', false),
                '',
            )
            ->assertDontSee('Review the calculated route')
            ->assertDontSee('Route review unavailable')
            ->assertNoJavaScriptErrors();

        expect($pattern->fresh()->routing_leg_distances)
            ->toEqual([30000.0])
            ->and(Http::recorded(
                fn ($request, $response): bool => parse_url(
                    $request->url(),
                    PHP_URL_HOST,
                ) === 'osrm.test',
            )->count())->toBe(1);

        BrowserSession::assertTokenWasValidated();
    });
});
