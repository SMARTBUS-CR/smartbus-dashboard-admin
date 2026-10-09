<?php

use App\Enums\LucideIcon;
use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\CreateStop;
use App\Filament\Resources\Stops\Pages\EditStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Livewire\Livewire;

describe('Stop Resource Map', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('uses a selected map point to create a company stop', function (float $latitude, float $longitude): void {
        Livewire::test(CreateStop::class)
            ->assertFormFieldExists('location')
            ->fillForm([
                'name' => 'Selected Boarding Point',
                'description' => null,
            ])
            ->set('data.location', [
                'lat' => $latitude,
                'lng' => $longitude,
            ])
            ->assertSchemaStateSet([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Selected Boarding Point',
            'latitude' => sprintf('%.7f', $latitude),
            'longitude' => sprintf('%.7f', $longitude),
        ]);
    })->with([
        'Costa Rican location' => [10.4523456, -84.0123456],
        'zero coordinates' => [0.0, 0.0],
    ]);

    test('loads the existing stop location into the map when editing', function (): void {
        $stop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertFormFieldExists('location')
            ->assertSet('data.location.lat', 10.4523456)
            ->assertSet('data.location.lng', -84.0123456)
            ->assertSchemaStateSet([
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ]);
    });

    test('updates the stop coordinates from the map without changing ownership', function (): void {
        $stop = Stop::factory()->for($this->company)->create([
            'name' => 'Central Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertFormFieldExists('location')
            ->set('data.location', [
                'lat' => 10.4623456,
                'lng' => -84.0223456,
            ])
            ->assertSchemaStateSet([
                'latitude' => 10.4623456,
                'longitude' => -84.0223456,
            ])
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

    test('updates the map point when coordinates are entered manually', function (): void {
        Livewire::test(CreateStop::class)
            ->set('data.latitude', '10.4523456')
            ->set('data.longitude', '-84.0123456')
            ->assertSet('data.location.lat', 10.4523456)
            ->assertSet('data.location.lng', -84.0123456);
    });

    test('updates the map point when existing coordinates are changed manually', function (): void {
        $stop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->set('data.latitude', '10.4623456')
            ->set('data.longitude', '-84.0223456')
            ->assertSet('data.location.lat', 10.4623456)
            ->assertSet('data.location.lng', -84.0223456);
    });

    test('clears the map point when a coordinate is removed', function (): void {
        $stop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->set('data.latitude', null)
            ->assertSet('data.location', null)
            ->call('save')
            ->assertHasFormErrors(['latitude' => 'required']);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);
    });

    test('clears the map point for invalid manual coordinates without changing the stop', function (string $field, string $value, string $rule): void {
        $stop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        Livewire::test(EditStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->set("data.{$field}", $value)
            ->assertSet('data.location', null)
            ->call('save')
            ->assertHasFormErrors([$field => $rule]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);
    })->with([
        'non-numeric latitude' => ['latitude', 'invalid', 'numeric'],
        'latitude outside its range' => ['latitude', '90.1', 'between'],
        'longitude outside its range' => ['longitude', '-180.1', 'between'],
    ]);

    test('uses a bus icon for the boarding point marker', function (bool $editing): void {
        if ($editing) {
            $stop = Stop::factory()->for($this->company)->create([
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ]);

            $component = Livewire::test(EditStop::class, [
                'record' => $stop->getRouteKey(),
            ]);
        } else {
            $component = Livewire::test(CreateStop::class);
        }

        $component->assertSchemaComponentExists(
            'location',
            checkComponentUsing: function (MapPicker $field): bool {
                $marker = $field->getPickMarkerData();

                expect(data_get($marker, 'icon.heroicon'))
                    ->toBe(
                        svg(LucideIcon::BusFront->value)->toHtml(),
                    );

                return true;
            },
        );
    })->with([
        'creating a stop' => false,
        'editing a stop' => true,
    ]);
});
