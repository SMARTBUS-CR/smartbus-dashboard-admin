<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\StopResource;
use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Stop Location Search Browser Flow', function (): void {
    test('searches for a location and saves the selected boarding point without manual coordinates', function (): void {
        config([
            'app.locale' => 'en',
            'services.photon.url' => 'https://photon.test',
        ]);

        app()->setLocale('en');

        $company = createCompany();

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://photon.test/api/*' => Http::response([
                    'type' => 'FeatureCollection',
                    'features' => [
                        [
                            'type' => 'Feature',
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [-84.0123456, 10.4523456],
                            ],
                            'properties' => [
                                'name' => 'Central Terminal',
                                'city' => 'Sarapiquí',
                                'country' => 'Costa Rica',
                                'countrycode' => 'CR',
                                'osm_key' => 'amenity',
                                'osm_value' => 'bus_station',
                            ],
                        ],
                    ],
                ]),
            ],
        );

        $path = parse_url(
            StopResource::getUrl('create', tenant: $company),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->type(
                Selector::getByLabelSelector('Stop Name', false),
                'Search Selected Stop',
            )
            ->click('[data-testid="location-search"] [role="combobox"]')
            ->type(
                '[data-testid="location-search"] input[aria-label="Search"]',
                'Central Terminal',
            )
            ->assertSee('Central Terminal, Sarapiquí, Costa Rica')
            ->click(Selector::getByRoleSelector('option', [
                'name' => 'Central Terminal, Sarapiquí, Costa Rica',
                'exact' => true,
            ]))
            ->assertVisible('.leaflet-marker-icon')
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Create',
                'exact' => true,
            ]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $company->getKey(),
            'name' => 'Search Selected Stop',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Http::assertSent(function ($request): bool {
            if (
                parse_url($request->url(), PHP_URL_HOST) !== 'photon.test'
                || parse_url($request->url(), PHP_URL_PATH) !== '/api/'
            ) {
                return false;
            }

            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            return ($query['q'] ?? null) === 'Central Terminal';
        });

        BrowserSession::assertTokenWasValidated();
    });
});
