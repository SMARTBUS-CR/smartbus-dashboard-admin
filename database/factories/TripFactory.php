<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Services\TripPlanSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory()->state(['timezone' => 'America/Costa_Rica']),
            'route_pattern_id' => fn (array $attributes): string => RoutePattern::factory()
                ->for(Route::factory()->state(['company_id' => $attributes['company_id']]))->withSavedPlan()->create()->getKey(),
            'service_date' => '2026-10-26',
            'route_schedule_id' => fn (array $attributes): string => RouteSchedule::factory()->create([
                'route_pattern_id' => $attributes['route_pattern_id'],
                'day_of_week' => CarbonImmutable::parse($attributes['service_date'])->dayOfWeek,
                'departure_time' => '06:30:00',
            ])->getKey(),
            'bus_id' => fn (array $attributes): string => Bus::factory()->active()->create(['company_id' => $attributes['company_id']])->getKey(),
            'driver_id' => fn (array $attributes): string => Driver::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'scheduled_departure_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                $attributes['service_date'].' '.RouteSchedule::query()->findOrFail($attributes['route_schedule_id'])->departure_time,
                Company::query()->findOrFail($attributes['company_id'])->timezone,
            )->utc(),
            'scheduled_arrival_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse($attributes['scheduled_departure_at'])->addHour(),
            'planned_route_snapshot' => function (array $attributes): array {
                $company = Company::query()->findOrFail($attributes['company_id']);
                $plan = app(TripPlanSnapshotService::class)->prepare($company, [
                    'route_pattern_id' => $attributes['route_pattern_id'], 'route_schedule_id' => $attributes['route_schedule_id'],
                    'bus_id' => $attributes['bus_id'], 'service_date' => $attributes['service_date'],
                    'arrival_at' => CarbonImmutable::parse($attributes['scheduled_arrival_at'])->setTimezone($company->timezone)->format('Y-m-d\TH:i:s.uP'),
                    'departure_utc_offset' => CarbonImmutable::parse($attributes['scheduled_departure_at'])->setTimezone($company->timezone)->format('P'),
                ]);

                return $plan['planned_route_snapshot'];
            },
        ];
    }

    /** Prepare identity eligibility only; this does not provide availability or reservations. */
    public function withEligibleDriver(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver_id' => function (array $attributes): string {
                $driver = Driver::factory()->create([
                    'company_id' => $attributes['company_id'],
                    'employment_starts_on' => $attributes['service_date'],
                    'employment_ends_on' => null,
                    'license_expires_at' => CarbonImmutable::parse($attributes['service_date'])->addYear()->toDateString(),
                ]);
                CompanyUser::factory()->create(['company_id' => $driver->company_id, 'user_id' => $driver->user_id]);
                $previousTeam = getPermissionsTeamId();
                try {
                    setPermissionsTeamId($driver->company_id);
                    $driver->user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
                } finally {
                    setPermissionsTeamId($previousTeam);
                }

                return $driver->getKey();
            },
        ]);
    }
}
