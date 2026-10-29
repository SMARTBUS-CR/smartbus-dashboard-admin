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

describe('Route Pattern Endpoint Browser Flow', function (): void {
    test('previews searched endpoints and saves them while preserving the intermediate stop', function (): void {
        config([
            'app.locale' => 'en',
            'services.photon.url' => 'https://photon.test',
        ]);

        app()->setLocale('en');

        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stops = [
            Stop::factory()->for($company)->create([
                'name' => 'Original Origin',
                'latitude' => '10.4000000',
                'longitude' => '-84.0000000',
            ]),
            Stop::factory()->for($company)->create([
                'name' => 'Intermediate Stop',
                'latitude' => '10.4500000',
                'longitude' => '-84.0500000',
            ]),
            Stop::factory()->for($company)->create([
                'name' => 'Original Destination',
                'latitude' => '10.5000000',
                'longitude' => '-84.1000000',
            ]),
        ];

        foreach ($stops as $index => $stop) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => null,
            ]);
        }

        $locations = [
            'Central Terminal' => [-84.0123456, 10.4523456],
            'North Terminal' => [-84.0223456, 10.4623456],
        ];

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://photon.test/api/*' => function ($request) use ($locations) {
                    parse_str(
                        (string) parse_url($request->url(), PHP_URL_QUERY),
                        $query,
                    );

                    $name = $query['q'] ?? '';
                    $coordinates = $locations[$name] ?? null;

                    return Http::response([
                        'type' => 'FeatureCollection',
                        'features' => $coordinates === null ? [] : [
                            [
                                'type' => 'Feature',
                                'geometry' => [
                                    'type' => 'Point',
                                    'coordinates' => $coordinates,
                                ],
                                'properties' => [
                                    'name' => $name,
                                    'country' => 'Costa Rica',
                                    'countrycode' => 'CR',
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
            ->assertVisible('.leaflet-container');

        $page->script(
            'window.scrollTo(0, document.documentElement.scrollHeight)',
        );

        $page->assertVisible(
            Selector::getByRoleSelector('row', [
                'name' => 'Original Origin',
                'exact' => false,
            ]),
        );

        $page
            ->click('[data-testid="origin_search"] [role="combobox"]')
            ->type(
                '[data-testid="origin_search"] input[aria-label="Search"]',
                'Central Terminal',
            )
            ->assertSee('Central Terminal, Costa Rica')
            ->click(Selector::getByRoleSelector('option', [
                'name' => 'Central Terminal, Costa Rica',
                'exact' => true,
            ]))
            ->click('[data-testid="destination_search"] [role="combobox"]')
            ->type(
                '[data-testid="destination_search"] input[aria-label="Search"]',
                'North Terminal',
            )
            ->assertSee('North Terminal, Costa Rica')
            ->click(Selector::getByRoleSelector('option', [
                'name' => 'North Terminal, Costa Rica',
                'exact' => true,
            ]));

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                () => {
                    const element = document.querySelector('.leaflet-container');
                    const root = element?.closest('[x-data]');
                    const component = root ? window.Alpine.$data(root) : null;

                    if (!component?.mapCore?.layers) {
                        return false;
                    }

                    const layers = window.Alpine.raw(component.mapCore.layers);
                    const points = [...layers.values()].map(({ layer }) =>
                        window.Alpine.raw(layer).getLatLng()
                    );

                    const expected = [
                        [10.4523456, -84.0123456],
                        [10.45, -84.05],
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

        expect($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe(array_map(
                fn (Stop $stop): string => $stop->getKey(),
                $stops,
            ));

        $page
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Save changes',
                'exact' => true,
            ]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        $page->script(
            'window.scrollTo(0, document.documentElement.scrollHeight)',
        );

        $page
            ->assertVisible(
                Selector::getByRoleSelector('row', [
                    'name' => 'Central Terminal',
                    'exact' => false,
                ]),
            )
            ->assertVisible(
                Selector::getByRoleSelector('row', [
                    'name' => 'North Terminal',
                    'exact' => false,
                ]),
            )
            ->assertNoJavaScriptErrors();

        $appearances = $pattern->stopOccurrences()->with('stop')->get();

        expect($appearances)->toHaveCount(3)
            ->and($appearances->first()->stop->name)->toBe('Central Terminal')
            ->and($appearances->get(1)->stop_id)->toBe($stops[1]->getKey())
            ->and($appearances->last()->stop->name)->toBe('North Terminal');

        $this->assertDatabaseHas(Stop::class, [
            'id' => $appearances->first()->stop_id,
            'company_id' => $company->getKey(),
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $appearances->last()->stop_id,
            'company_id' => $company->getKey(),
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        BrowserSession::assertTokenWasValidated();
    });
});
