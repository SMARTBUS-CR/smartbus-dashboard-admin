<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\CompanyUser;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

describe('Trip Resource Filters', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-27T03:00:00Z'));
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($this->actor, 'user')->create();
        grantShield($this->actor, ['ViewAny:Trip', 'View:Trip'], $this->company);
    });

    test('opens on the company local day and supports the full local week and all dates', function (): void {
        $today = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        $tomorrow = Trip::factory()->for($this->company)->withEligibleDriver()->create(['service_date' => '2026-10-27']);
        $lastSunday = Trip::factory()->for($this->company)->withEligibleDriver()->create(['service_date' => '2026-10-25']);
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(ListTrips::class)
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$tomorrow, $lastSunday])
            ->filterTable('service_date', ['period' => 'this_week'])
            ->assertCanSeeTableRecords([$today, $tomorrow])
            ->assertCanNotSeeTableRecords([$lastSunday])
            ->filterTable('service_date', ['period' => 'all'])
            ->assertCanSeeTableRecords([$today, $tomorrow, $lastSunday]);
    });

    test('rejects an inverted custom date range before applying filters', function (): void {
        $today = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(ListTrips::class)
            ->set('tableDeferredFilters.service_date', [
                'period' => 'custom', 'from_date' => '2026-10-27', 'until_date' => '2026-10-26',
            ])
            ->call('applyTableFilters')
            ->assertHasErrors(['tableDeferredFilters.service_date.until_date' => 'after_or_equal'])
            ->assertCanSeeTableRecords([$today]);
    });

    test('filters company trips by local dates status driver bus and schedule', function (): void {
        $completed = Trip::factory()->for($this->company)->withEligibleDriver()->completed()->create([
            'service_date' => '2026-10-26',
        ]);
        $scheduled = Trip::factory()->for($this->company)->withEligibleDriver()->create([
            'service_date' => '2026-10-27',
        ]);
        $foreign = Trip::factory()->withEligibleDriver()->completed()->create([
            'service_date' => '2026-10-26',
        ]);
        actingAsInCompany($this->actor, $this->company);

        $component = Livewire::test(ListTrips::class)
            ->filterTable('service_date', ['period' => 'custom', 'from_date' => '2026-10-26'])
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign])
            ->resetTableFilters()
            ->filterTable('service_date', ['period' => 'custom', 'from_date' => '2026-10-26', 'until_date' => '2026-10-27'])
            ->assertCanSeeTableRecords([$completed, $scheduled])
            ->assertCanNotSeeTableRecords([$foreign])
            ->resetTableFilters()
            ->filterTable('status', TripStatus::COMPLETED)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign])
            ->resetTableFilters()
            ->filterTable('driver_id', $completed->driver_id)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign])
            ->resetTableFilters()
            ->filterTable('bus_id', $completed->bus_id)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign])
            ->resetTableFilters()
            ->filterTable('route_schedule_id', $completed->route_schedule_id)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign])
            ->resetTableFilters();

        $component
            ->filterTable('service_date', ['period' => 'custom', 'from_date' => '2026-10-26', 'until_date' => '2026-10-27'])
            ->filterTable('status', TripStatus::COMPLETED)
            ->filterTable('driver_id', $completed->driver_id)
            ->filterTable('bus_id', $completed->bus_id)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled, $foreign]);
    });

    test('keeps archived and foreign filter values within company boundaries', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        $foreign = Trip::factory()->withEligibleDriver()->create();
        $trip->driver->delete();
        $trip->bus->delete();
        actingAsInCompany($this->actor, $this->company);

        $component = Livewire::test(ListTrips::class);
        $driverOptions = $component->instance()->getTable()->getFilter('driver_id')->getOptions();
        $busOptions = $component->instance()->getTable()->getFilter('bus_id')->getOptions();
        $scheduleOptions = $component->instance()->getTable()->getFilter('route_schedule_id')->getOptions();

        expect($driverOptions)->toHaveKey($trip->driver_id)->not->toHaveKey($foreign->driver_id)
            ->and($busOptions)->toHaveKey($trip->bus_id)->not->toHaveKey($foreign->bus_id)
            ->and($scheduleOptions)->toHaveKey($trip->route_schedule_id)->not->toHaveKey($foreign->route_schedule_id);

        $component
            ->filterTable('driver_id', $foreign->driver_id)
            ->assertCanNotSeeTableRecords([$trip, $foreign])
            ->resetTableFilters()
            ->filterTable('bus_id', $foreign->bus_id)
            ->assertCanNotSeeTableRecords([$trip, $foreign])
            ->resetTableFilters()
            ->filterTable('route_schedule_id', $foreign->route_schedule_id)
            ->assertCanNotSeeTableRecords([$trip, $foreign])
            ->resetTableFilters()
            ->filterTable('service_date', ['period' => 'custom', 'from_date' => 'not-a-date'])
            ->assertCanNotSeeTableRecords([$trip, $foreign])
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete');
    });
});
