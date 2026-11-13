<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\TripStatusOverview;
use App\Models\CompanyUser;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

describe('Trip Status Overview', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($this->actor, 'user')->create();
        grantShield($this->actor, ['ViewAny:Trip'], $this->company);
    });

    test('shows exact company status counts for an inclusive local date range', function (): void {
        $template = Trip::factory()->for($this->company)->withEligibleDriver()->create([
            'service_date' => '2026-10-26',
        ]);
        $createTrip = function (string $serviceDate, TripStatus $status, int $hour) use ($template): Trip {
            $departure = CarbonImmutable::parse("{$serviceDate} 00:00:00", 'UTC')->addHours($hour);
            $schedule = RouteSchedule::factory()->create([
                'route_pattern_id' => $template->route_pattern_id,
                'day_of_week' => CarbonImmutable::parse($serviceDate)->dayOfWeek,
                'departure_time' => $departure->format('H:i:s'),
            ]);
            $trip = Trip::factory()->create([
                'company_id' => $template->company_id,
                'route_pattern_id' => $template->route_pattern_id,
                'route_schedule_id' => $schedule->getKey(),
                'bus_id' => $template->bus_id,
                'driver_id' => $template->driver_id,
                'service_date' => $serviceDate,
                'scheduled_departure_at' => $departure,
                'scheduled_arrival_at' => $departure->addHour(),
                'planned_route_snapshot' => $template->planned_route_snapshot,
            ]);

            if ($status !== TripStatus::SCHEDULED) {
                Trip::query()->whereKey($trip->getKey())->update([
                    'status' => $status->value,
                    'actual_departure_at' => $status === TripStatus::CANCELLED ? null : $departure,
                    'actual_arrival_at' => $status === TripStatus::COMPLETED ? $departure->addHour() : null,
                    'cancelled_at' => $status === TripStatus::CANCELLED ? $departure->subHour() : null,
                    'cancellation_reason' => $status === TripStatus::CANCELLED ? 'Operational cancellation' : null,
                ]);
                $trip->refresh();
            }

            return $trip;
        };

        $createTrip('2026-10-26', TripStatus::SCHEDULED, 9);
        $inProgress = $createTrip('2026-10-26', TripStatus::IN_PROGRESS, 11);
        $completed = $createTrip('2026-10-27', TripStatus::COMPLETED, 13);
        $cancelled = $createTrip('2026-10-27', TripStatus::CANCELLED, 15);
        $foreign = Trip::factory()->completed()->create(['service_date' => '2026-10-26']);
        $template->driver->forceFill(['salary_amount' => '987654.32'])->save();
        $privateEmail = $template->driver->user->email;
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'from_date' => '2026-10-26',
            'until_date' => '2026-10-27',
        ]])
            ->assertSuccessful()
            ->assertSeeInOrder(['Scheduled', '2', 'In progress', '1', 'Completed', '1', 'Cancelled', '1'])
            ->assertDontSee($foreign->getKey())
            ->assertDontSee($privateEmail)
            ->assertDontSee('987654.32')
            ->assertDontSee('Revenue')
            ->assertDontSee('Attendance');

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'from_date' => '2026-10-27',
        ]])
            ->assertSeeInOrder(['Scheduled', '0', 'In progress', '0', 'Completed', '1', 'Cancelled', '1']);

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'from_date' => '2026-11-01',
        ]])
            ->assertSeeInOrder(['Scheduled', '0', 'In progress', '0', 'Completed', '0', 'Cancelled', '0']);

        expect($inProgress->status)->toBe(TripStatus::IN_PROGRESS)
            ->and($completed->status)->toBe(TripStatus::COMPLETED)
            ->and($cancelled->status)->toBe(TripStatus::CANCELLED);
    });

    test('denies status counts without trip listing authorization', function (): void {
        $unauthorized = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($unauthorized, 'user')->create();
        actingAsInCompany($unauthorized, $this->company);

        Livewire::test(TripStatusOverview::class)
            ->assertForbidden();
    });

    test('uses the tenant local day and allows the global super administrator', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->create([
            'service_date' => '2026-10-26',
        ]);
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $this->travelTo('2026-10-27 02:00:00 UTC');
        actingAsInCompany($superAdmin, $this->company);

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSet('filters.period', 'today')
            ->assertDontSee('From Date')
            ->assertDontSee('Until Date')
            ->assertSeeInOrder(['Welcome', 'Filament', 'Date Range', 'Trip Operations'])
            ->set('filters.period', 'custom')
            ->assertSee('From Date')
            ->assertSee('Until Date');

        Livewire::test(TripStatusOverview::class)
            ->assertSuccessful()
            ->assertSeeInOrder(['Scheduled', '1', 'In progress', '0', 'Completed', '0', 'Cancelled', '0'])
            ->assertDontSee($trip->driver->salary_amount);
    });

    test('counts rolling periods inclusively using the company local day', function (string $period, int $count): void {
        $this->travelTo('2026-11-01 02:00:00 UTC');
        foreach (['2026-10-01', '2026-10-24', '2026-10-25', '2026-10-31', '2026-11-01'] as $date) {
            Trip::factory()->for($this->company)->withEligibleDriver()->create(['service_date' => $date]);
        }
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'period' => $period,
            'from_date' => '2026-11-01',
        ]])->assertSeeInOrder(['Scheduled', (string) $count, 'In progress', '0']);
    })->with([['today', 1], ['last_7_days', 2], ['last_30_days', 3]]);

    test('hides the range controls when the trip summary is forbidden', function (): void {
        $unauthorized = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($unauthorized, 'user')->create();
        actingAsInCompany($unauthorized, $this->company);

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertDontSee('Date Range')
            ->assertDontSee('From Date')
            ->assertDontSee('Trip Operations');
    });

    test('reports inverted custom dates and clears the error after correction', function (): void {
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(Dashboard::class)
            ->set('filters.period', 'custom')
            ->set('filters.from_date', '2026-10-27')
            ->set('filters.until_date', '2026-10-26')
            ->assertHasErrors(['filters.until_date' => 'after_or_equal'])
            ->set('filters.until_date', '2026-10-28')
            ->assertHasNoErrors(['filters.until_date']);
    });

    test('shows dates from an existing dashboard link as a custom range', function (): void {
        actingAsInCompany($this->actor, $this->company);

        Livewire::withQueryParams(['filters' => ['from_date' => '2026-10-01', 'until_date' => '2026-10-15']])
            ->test(Dashboard::class)
            ->assertSet('filters.period', 'custom')
            ->assertSet('filters.from_date', '2026-10-01')
            ->assertSee('From Date');
    });

    test('keeps aggregate query growth bounded as trip volume increases', function (): void {
        $template = Trip::factory()->for($this->company)->withEligibleDriver()->create([
            'service_date' => '2026-10-01',
        ]);
        actingAsInCompany($this->actor, $this->company);
        $connection = DB::connection('pgsql');
        $connection->enableQueryLog();

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'from_date' => '2026-10-01',
            'until_date' => '2026-10-31',
        ]])->assertSuccessful();
        $smallQueryCount = collect($connection->getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "trips"'))
            ->count();

        foreach (range(2, 21) as $day) {
            $date = sprintf('2026-10-%02d', $day);
            Trip::factory()->create([
                'company_id' => $template->company_id,
                'route_pattern_id' => $template->route_pattern_id,
                'route_schedule_id' => $template->route_schedule_id,
                'bus_id' => $template->bus_id,
                'driver_id' => $template->driver_id,
                'service_date' => $date,
                'scheduled_departure_at' => "{$date}T12:00:00Z",
                'scheduled_arrival_at' => "{$date}T13:00:00Z",
                'planned_route_snapshot' => $template->planned_route_snapshot,
            ]);
        }
        $connection->flushQueryLog();

        Livewire::test(TripStatusOverview::class, ['pageFilters' => [
            'from_date' => '2026-10-01',
            'until_date' => '2026-10-31',
        ]])->assertSuccessful();
        $largeQueryCount = collect($connection->getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "trips"'))
            ->count();

        expect($smallQueryCount)->toBe(1)
            ->and($largeQueryCount)->toBe(1);
        $connection->disableQueryLog();
    });
});
