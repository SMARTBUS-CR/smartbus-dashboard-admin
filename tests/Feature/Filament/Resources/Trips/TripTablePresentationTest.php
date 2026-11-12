<?php

use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\CompanyUser;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('shows the scheduled hours in the saved timezone and marks an overnight arrival', function (string $timezone, string $serviceDate, string $arrival): void {
    $this->travelTo(CarbonImmutable::parse($serviceDate.'T12:30:00Z'));
    $company = createCompany(['timezone' => $timezone]);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

    $trip = Trip::factory()->for($company)->withEligibleDriver()->create([
        'service_date' => $serviceDate,
        'route_schedule_id' => fn (array $attributes): string => RouteSchedule::factory()->create([
            'route_pattern_id' => $attributes['route_pattern_id'],
            'day_of_week' => CarbonImmutable::parse($serviceDate)->dayOfWeek,
            'departure_time' => '23:30:00',
        ])->getKey(),
        'scheduled_arrival_at' => $arrival,
    ]);
    $company->update(['timezone' => 'UTC']);
    actingAsInCompany($actor, $company);
    app()->setLocale('en');

    Livewire::test(ListTrips::class)
        ->assertCanSeeTableRecords([$trip])
        ->assertSee('Scheduled Time')
        ->assertSee('11:30 PM → 01:00 AM (+1 day)')
        ->assertDontSee('Scheduled Arrival');
})->with([
    'regular midnight' => ['America/Costa_Rica', '2026-10-26', '2026-10-27T07:00:00Z'],
    'midnight after a daylight-saving transition' => ['America/Santiago', '2026-09-06', '2026-09-07T04:00:00Z'],
]);

test('shows the saved route and both identifiers for archived trip assignments', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:30:00Z'));
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

    $trip = Trip::factory()->for($company)->withEligibleDriver()->completed()->create();
    $snapshot = $trip->planned_route_snapshot;
    $bus = $trip->bus;
    $driver = $trip->driver;
    $driverName = $driver->user->name;
    $trip->pattern->update(['name' => 'Renamed live pattern']);
    $bus->delete();
    $driver->delete();
    actingAsInCompany($actor, $company);
    app()->setLocale('en');

    Livewire::test(ListTrips::class)
        ->assertTableColumnStateSet('planned_route_snapshot.pattern_code', $snapshot['pattern_code'], $trip)
        ->assertSee($snapshot['pattern_name'])
        ->assertDontSee('Renamed live pattern')
        ->assertTableColumnStateSet('bus.unit_number', $bus->unit_number, $trip)
        ->assertSee($bus->plate_number)
        ->assertTableColumnStateSet('driver_name', $driverName, $trip)
        ->assertSee('Lic. '.$driver->license);
});

test('keeps trips ordered by departure when assignment columns are hidden', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:30:00Z'));
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

    $later = Trip::factory()->for($company)->withEligibleDriver()->create([
        'route_schedule_id' => fn (array $attributes): string => RouteSchedule::factory()->create([
            'route_pattern_id' => $attributes['route_pattern_id'],
            'day_of_week' => CarbonImmutable::MONDAY,
            'departure_time' => '13:30:00',
        ])->getKey(),
    ]);
    $earlier = Trip::factory()->for($company)->withEligibleDriver()->create();
    actingAsInCompany($actor, $company);

    Livewire::test(ListTrips::class)
        ->assertCanSeeTableRecords([$earlier, $later], inOrder: true)
        ->assertCanRenderTableColumn('bus.unit_number')
        ->assertCanRenderTableColumn('driver_name')
        ->toggleAllTableColumns(false)
        ->assertCanNotRenderTableColumn('bus.unit_number')
        ->assertCanNotRenderTableColumn('driver_name')
        ->assertCanRenderTableColumn('service_date')
        ->assertCanSeeTableRecords([$earlier, $later], inOrder: true);
});
