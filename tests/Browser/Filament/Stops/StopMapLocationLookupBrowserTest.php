<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\StopResource;
use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Stop Map Location Lookup', function (): void {
    test('shows a busy overlay while describing a selected point and preserves the stop name', function (): void {
        config([
            'app.locale' => 'en',
            'services.photon.url' => 'https://photon.test',
        ]);

        app()->setLocale('en');

        $company = createCompany();

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
            [
                'https://photon.test/reverse*' => function () {
                    return Http::response([
                        'type' => 'FeatureCollection',
                        'features' => [
                            [
                                'type' => 'Feature',
                                'geometry' => [
                                    'type' => 'Point',
                                    'coordinates' => [-84.0907, 9.9281],
                                ],
                                'properties' => [
                                    'name' => 'Central Terminal',
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
            StopResource::getUrl(
                'create',
                panel: 'admin',
                tenant: $company,
            ),
            PHP_URL_PATH,
        );

        $listPath = parse_url(
            StopResource::getUrl(
                'index',
                panel: 'admin',
                tenant: $company,
            ),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->type(
                Selector::getByLabelSelector('Stop Name', false),
                'Operator Defined Stop',
            )
            ->assertVisible('.leaflet-container')
            ->assertPresent('[data-testid="map-location-busy"]');

        $page->script(<<<'JS'
            () => {
                const overlay = document.querySelector(
                    '[data-testid="map-location-busy"]'
                );

                window.stopLookupStates = [];

                const capture = () => {
                    const style = window.getComputedStyle(overlay);

                    window.stopLookupStates.push({
                        visible: overlay.getClientRects().length > 0
                            && style.display !== 'none'
                            && style.visibility !== 'hidden',
                        text: overlay.textContent.trim(),
                    });
                };

                window.stopLookupObserver = new MutationObserver(capture);

                window.stopLookupObserver.observe(overlay, {
                    attributes: true,
                    attributeFilter: ['style', 'class', 'hidden'],
                    childList: true,
                    characterData: true,
                    subtree: true,
                });

                capture();
            }
            JS);

        $page
            ->click('.leaflet-container')
            ->assertSee('Central Terminal, Costa Rica')
            ->assertMissing('[data-testid="map-location-busy"]');

        $page->assertScript(<<<'JS'
        () => {
            return window.stopLookupStates.some(
                state => state.visible
                    && state.text.includes(
                        'Looking up location information...'
                    )
            );
        }
        JS);

        $page->script('() => window.stopLookupObserver.disconnect()');

        $page->assertValue(
            Selector::getByLabelSelector('Stop Name', false),
            'Operator Defined Stop',
        )
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Create',
                'exact' => true,
            ]))
            ->assertPathIs($listPath)
            ->assertSee('Operator Defined Stop')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $company->getKey(),
            'name' => 'Operator Defined Stop',
        ]);

        BrowserSession::assertTokenWasValidated();
    });
});
