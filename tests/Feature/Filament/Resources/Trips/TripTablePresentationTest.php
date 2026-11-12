<?php

use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\CompanyUser;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('shows the scheduled hours in the saved timezone and marks an overnight arrival', function (): void {
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

    $trip = Trip::factory()->for($company)->withEligibleDriver()->create([
        'service_date' => '2026-10-26',
        'route_schedule_id' => fn (array $attributes): string => RouteSchedule::factory()->create([
            'route_pattern_id' => $attributes['route_pattern_id'],
            'day_of_week' => CarbonImmutable::MONDAY,
            'departure_time' => '23:30:00',
        ])->getKey(),
        'scheduled_arrival_at' => '2026-10-27T07:00:00Z',
    ]);
    $company->update(['timezone' => 'UTC']);
    actingAsInCompany($actor, $company);
    app()->setLocale('en');

    Livewire::test(ListTrips::class)
        ->assertCanSeeTableRecords([$trip])
        ->assertSee('Scheduled Time')
        ->assertSee('11:30 PM → 01:00 AM (+1 day)')
        ->assertDontSee('Scheduled Arrival');
});
