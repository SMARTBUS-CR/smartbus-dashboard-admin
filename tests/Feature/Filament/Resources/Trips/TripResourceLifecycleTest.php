<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\CompanyUser;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

describe('Trip Resource Lifecycle', function (): void {
    test('starts and completes a trip through the canonical lifecycle service', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        actingAsInCompany($actor, $trip->company);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));

        Livewire::test(ListTrips::class)
            ->callAction(TestAction::make('start')->table($trip))
            ->assertNotified();

        $trip->refresh();
        expect($trip->status)->toBe(TripStatus::IN_PROGRESS)
            ->and($trip->actual_departure_at->utc()->toIso8601String())->toBe('2026-10-26T12:45:00+00:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));

        Livewire::test(ListTrips::class)
            ->callAction(TestAction::make('complete')->table($trip))
            ->assertNotified();

        $trip->refresh();
        expect($trip->status)->toBe(TripStatus::COMPLETED)
            ->and($trip->actual_arrival_at->utc()->toIso8601String())->toBe('2026-10-26T13:45:00+00:00');
        Http::assertNothingSent();
    });

    test('requires a reason and preserves the cancelled trip as history', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        actingAsInCompany($actor, $trip->company);
        $before = $trip->getRawOriginal();

        Livewire::test(ListTrips::class)
            ->callAction(TestAction::make('cancel')->table($trip), [
                'cancellation_reason' => '',
            ])
            ->assertHasFormErrors(['cancellation_reason']);

        expect($trip->fresh()->getRawOriginal())->toBe($before);

        Livewire::test(ListTrips::class)
            ->callAction(TestAction::make('cancel')->table($trip), [
                'cancellation_reason' => '  Vehicle unavailable.  ',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $trip->refresh();
        expect($trip->status)->toBe(TripStatus::CANCELLED)
            ->and($trip->cancellation_reason)->toBe('Vehicle unavailable.')
            ->and($trip->cancelled_at)->not->toBeNull();
        $this->assertModelExists($trip);
        $this->assertDatabaseCount('trips', 1);
    });

    test('rejects a forged cancellation after execution starts and exposes no deletion actions', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        actingAsInCompany($actor, $trip->company);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
        app(TripManagementService::class)->start($actor, $trip->company, $trip->getKey());
        $before = $trip->fresh()->getRawOriginal();

        Livewire::test(ListTrips::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($trip))
            ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($trip))
            ->call('mountAction', 'cancel', [], [
                'table' => true,
                'recordKey' => $trip->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($trip->fresh()->getRawOriginal())->toBe($before);
    });

    test('rejects a forged lifecycle action without its permission', function (): void {
        [, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::factory()->for($company)->for($actor, 'user')->create();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListTrips::class)
            ->call('mountAction', 'start', [], [
                'table' => true,
                'recordKey' => $trip->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);
    });

    test('rejects a forged lifecycle action across company boundaries', function (): void {
        [, $trip] = $this->createAssignedDriverTrip();
        $otherCompany = createCompany();

        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $otherCompany);

        Livewire::test(ListTrips::class)
            ->call('mountAction', 'start', [], [
                'table' => true,
                'recordKey' => $trip->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);
    });
});
