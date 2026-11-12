<?php

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\CreateTrip;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\TripManagementService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

describe('Trip Resource Replacement', function (): void {
    test('prefills and creates a separate replacement for a cancelled trip', function (): void {
        [$actor, $cancelled] = $this->createAssignedDriverTrip(TripStatus::CANCELLED->value);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $cancelled->company);
        actingAsInCompany($actor, $cancelled->company);
        $cancelledBefore = $cancelled->getRawOriginal();
        $replacementUrl = TripResource::getUrl('create', [
            'replacement' => $cancelled->getKey(),
        ], tenant: $cancelled->company);

        Livewire::test(ListTrips::class)
            ->assertActionHasUrl(
                TestAction::make('replace')->table($cancelled),
                $replacementUrl,
            );

        Livewire::withQueryParams(['replacement' => $cancelled->getKey()])
            ->test(CreateTrip::class)
            ->assertSchemaStateSet([
                'route_pattern_id' => $cancelled->route_pattern_id,
                'route_schedule_id' => $cancelled->route_schedule_id,
                'service_date' => $cancelled->service_date->toDateString(),
                'bus_id' => $cancelled->bus_id,
                'driver_id' => $cancelled->driver_id,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $replacement = Trip::query()->whereKeyNot($cancelled->getKey())->sole();
        expect($replacement->status)->toBe(TripStatus::SCHEDULED)
            ->and($replacement->route_schedule_id)->toBe($cancelled->route_schedule_id)
            ->and($replacement->service_date->toDateString())->toBe($cancelled->service_date->toDateString())
            ->and($cancelled->fresh()->getRawOriginal())->toBe($cancelledBefore);
        $this->assertDatabaseCount('trips', 2);
    });

    test('rejects a conflicting replacement without changing cancelled history', function (): void {
        [$actor, $cancelled] = $this->createAssignedDriverTrip(TripStatus::CANCELLED->value);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $cancelled->company);
        actingAsInCompany($actor, $cancelled->company);
        $data = [
            ...$cancelled->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => $cancelled->service_date->toDateString(),
            'arrival_at' => $cancelled->scheduled_arrival_at
                ->setTimezone($cancelled->company->timezone)
                ->format('Y-m-d\TH:i:sP'),
        ];
        app(TripManagementService::class)->schedule($actor, $cancelled->company, $data);
        $cancelledBefore = $cancelled->fresh()->getRawOriginal();

        Livewire::withQueryParams(['replacement' => $cancelled->getKey()])
            ->test(CreateTrip::class)
            ->call('create')
            ->assertHasFormErrors(['service_date']);

        expect($cancelled->fresh()->getRawOriginal())->toBe($cancelledBefore);
        $this->assertDatabaseCount('trips', 2);
    });

    test('does not prefill an unavailable bus and rejects a forged replacement assignment', function (): void {
        [$actor, $cancelled] = $this->createAssignedDriverTrip(TripStatus::CANCELLED->value);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $cancelled->company);
        actingAsInCompany($actor, $cancelled->company);
        $cancelled->bus->forceFill(['is_active' => false])->save();
        $cancelledBefore = $cancelled->fresh()->getRawOriginal();

        Livewire::withQueryParams(['replacement' => $cancelled->getKey()])
            ->test(CreateTrip::class)
            ->assertSchemaStateSet(['bus_id' => null])
            ->fillForm(['bus_id' => $cancelled->bus_id])
            ->call('create')
            ->assertHasFormErrors(['bus_id']);

        expect($cancelled->fresh()->getRawOriginal())->toBe($cancelledBefore);
        $this->assertDatabaseCount('trips', 1);
    });
});
