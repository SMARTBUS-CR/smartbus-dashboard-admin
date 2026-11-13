<?php

use App\Enums\UserRole;
use App\Filament\Forms\Components\LocationSearchInput;
use App\Filament\Resources\Stops\Pages\CreateStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Stop Map Location Description', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        config([
            'services.photon.url' => 'https://photon.test',
        ]);

        Http::preventStrayRequests();

        $this->company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('describes a map point without changing the stop name or selected coordinates', function (): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-84.0123, 10.4523],
                        ],
                        'properties' => [
                            'name' => 'Central Terminal',
                            'country' => 'Costa Rica',
                            'countrycode' => 'CR',
                        ],
                    ],
                ],
            ]),
        ]);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Company Boarding Point',
            ])
            ->call(
                'callSchemaComponentMethod',
                'form.location',
                'handleMapClick',
                [
                    'latitude' => 10.4523456,
                    'longitude' => -84.0123456,
                ],
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'name' => 'Company Boarding Point',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->assertSchemaComponentExists(
                'location_search',
                checkComponentUsing: function (LocationSearchInput $field): bool {
                    $result = $field->getState();

                    expect($result)->toBeInstanceOf(GeoSearchResult::class)
                        ->and($result->name)->toBe('Central Terminal')
                        ->and($result->coordinate->lat)->toBe(10.4523456)
                        ->and($result->coordinate->lng)->toBe(-84.0123456);

                    return true;
                },
            )
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Company Boarding Point',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Http::assertSentCount(1);
    });

    test('keeps the selected point and stop name when no description is available', function (int $status): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [],
            ], $status),
        ]);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Operator Defined Stop',
            ])
            ->call(
                'callSchemaComponentMethod',
                'form.location',
                'handleMapClick',
                [
                    'latitude' => 10.4523456,
                    'longitude' => -84.0123456,
                ],
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'name' => 'Operator Defined Stop',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->assertSchemaComponentExists(
                'location_search',
                checkComponentUsing: function (LocationSearchInput $field): bool {
                    $result = $field->getState();

                    expect($result)->toBeInstanceOf(GeoSearchResult::class)
                        ->and($result->displayName)
                        ->toBe('Selected Location (10.4523456, -84.0123456)')
                        ->and($result->coordinate->lat)->toBe(10.4523456)
                        ->and($result->coordinate->lng)->toBe(-84.0123456);

                    return true;
                },
            )
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Operator Defined Stop',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Http::assertSentCount(1);
    })->with([
        'no matching location' => 200,
        'provider unavailable' => 503,
    ]);
});
