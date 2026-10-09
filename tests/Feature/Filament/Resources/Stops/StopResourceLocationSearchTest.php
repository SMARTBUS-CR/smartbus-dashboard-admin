<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\CreateStop;
use App\Filament\Resources\Stops\Pages\EditStop;
use App\Models\Stop;
use Livewire\Livewire;

describe('Stop Resource Location Search', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('creates a stop using the selected search result without manual coordinates', function (float $latitude, float $longitude): void {
        $result = [
            'coordinate' => [
                'lat' => $latitude,
                'lng' => $longitude,
            ],
            'name' => 'Central Terminal',
            'display_name' => 'Central Terminal, Costa Rica',
            'type' => 'bus_station',
            'addresstype' => 'amenity',
            'address' => [],
            'boundingbox' => [],
        ];

        Livewire::test(CreateStop::class)
            ->assertSchemaComponentExists('location_search')
            ->fillForm([
                'name' => 'Central Terminal',
                'description' => null,
            ])
            ->set('data.location_search', json_encode($result))
            ->assertSchemaStateSet([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ])
            ->assertSet('data.location.lat', $latitude)
            ->assertSet('data.location.lng', $longitude)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Central Terminal',
            'latitude' => sprintf('%.7f', $latitude),
            'longitude' => sprintf('%.7f', $longitude),
        ]);
    })->with([
        'regular location' => [10.4523456, -84.0123456],
        'zero coordinates' => [0.0, 0.0],
    ]);

    test('updates the boarding point from a selected search result without changing ownership', function (): void {
        $stop = Stop::factory()->for($this->company)->create([
            'name' => 'Central Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $result = [
            'coordinate' => [
                'lat' => 10.4623456,
                'lng' => -84.0223456,
            ],
            'name' => 'North Terminal',
            'display_name' => 'North Terminal, Costa Rica',
            'type' => 'bus_station',
            'addresstype' => 'amenity',
            'address' => [],
            'boundingbox' => [],
        ];

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertSchemaComponentExists('location_search')
            ->set('data.location_search', json_encode($result))
            ->assertSet('data.location.lat', 10.4623456)
            ->assertSet('data.location.lng', -84.0223456)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => $this->company->getKey(),
            'name' => 'Central Terminal',
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);
    });
});
