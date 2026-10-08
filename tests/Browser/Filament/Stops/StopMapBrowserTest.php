<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\StopResource;
use App\Models\Stop;
use Pest\Browser\Execution;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Stop Map Browser Flows', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
    });

    test('selects a boarding point with a map click and saves its coordinates', function (): void {
        $company = createCompany();

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
        );

        $latitudeField = Selector::getByLabelSelector('Latitude', false);
        $longitudeField = Selector::getByLabelSelector('Longitude', false);

        $path = parse_url(
            StopResource::getUrl('create', tenant: $company),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->type(
                Selector::getByLabelSelector('Stop Name', false),
                'Map Selected Stop',
            )
            ->assertVisible('.leaflet-container')
            ->click('.leaflet-container')
            ->assertVisible('.leaflet-marker-icon');

        Execution::instance()->waitForExpectation(function () use ($page, $latitudeField, $longitudeField): void {
            expect($page->value($latitudeField))->not->toBeEmpty()
                ->and($page->value($longitudeField))->not->toBeEmpty();
        });

        $latitude = $page->value($latitudeField);
        $longitude = $page->value($longitudeField);

        expect($latitude)->toBeNumeric()
            ->and($longitude)->toBeNumeric();

        $page
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Create',
                'exact' => true,
            ]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $company->getKey(),
            'name' => 'Map Selected Stop',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        BrowserSession::assertTokenWasValidated();
    });

    test('moves the marker after manual coordinate changes and saves the new location', function (): void {
        $company = createCompany();

        $stop = Stop::factory()->for($company)->create([
            'name' => 'Central Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
        );

        $latitudeField = Selector::getByLabelSelector('Latitude', false);
        $longitudeField = Selector::getByLabelSelector('Longitude', false);

        $path = parse_url(
            StopResource::getUrl(
                'edit',
                ['record' => $stop],
                tenant: $company,
            ),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->assertVisible('.leaflet-container')
            ->assertVisible('.leaflet-marker-icon')
            ->click('Advanced Coordinates')
            ->assertValue($latitudeField, '10.4523456')
            ->assertValue($longitudeField, '-84.0123456')
            ->type($latitudeField, '10.4623456')
            ->keys($latitudeField, ['Tab'])
            ->type($longitudeField, '-84.0223456')
            ->keys($longitudeField, ['Tab']);

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
                () => {
                    const element = document.querySelector('.leaflet-container');
                    const root = element?.closest('[x-data]');
                    const component = root ? window.Alpine.$data(root) : null;
                    const marker = component?.pickMarker;

                    if (!marker) {
                        return false;
                    }

                    const point = window.Alpine.raw(marker).getLatLng();

                    return Math.abs(point.lat - 10.4623456) < 0.0000001
                        && Math.abs(point.lng + 84.0223456) < 0.0000001;
                }
                JS);
        });

        $page
            ->click(Selector::getByRoleSelector('button', [
                'name' => 'Save changes',
                'exact' => true,
            ]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => $company->getKey(),
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        BrowserSession::assertTokenWasValidated();
    });
});
