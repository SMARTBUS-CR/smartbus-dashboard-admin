<?php

namespace Database\Factories;

use App\Enums\TripAssignmentStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\TripAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripAssignment>
 */
class TripAssignmentFactory extends Factory
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
            'route_schedule_id' => fn (array $attributes): string => RouteSchedule::factory()
                ->for(RoutePattern::factory()->for(
                    Route::factory()->state(['company_id' => $attributes['company_id']]),
                ), 'pattern')
                ->create([
                    'day_of_week' => 1,
                    'departure_time' => '06:30:00',
                ])
                ->getKey(),
            'driver_id' => fn (array $attributes): string => Driver::factory()
                ->create(['company_id' => $attributes['company_id']])
                ->getKey(),
            'bus_id' => fn (array $attributes): string => Bus::factory()
                ->active()
                ->create(['company_id' => $attributes['company_id']])
                ->getKey(),
            'planned_arrival_time' => '07:30:00',
            'ends_next_day' => false,
            'valid_from' => null,
            'valid_until' => null,
            'status' => TripAssignmentStatus::ACTIVE,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TripAssignmentStatus::ACTIVE,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TripAssignmentStatus::INACTIVE,
        ]);
    }

    public function archived(): static
    {
        return $this->afterCreating(function (TripAssignment $assignment): void {
            $assignment->delete();
        });
    }

    public function sameDay(): static
    {
        return $this->state(fn (array $attributes): array => [
            'planned_arrival_time' => '07:30:00',
            'ends_next_day' => false,
        ]);
    }

    public function overnight(): static
    {
        return $this->state(fn (array $attributes): array => [
            'planned_arrival_time' => '05:30:00',
            'ends_next_day' => true,
        ]);
    }

    public function fullDay(): static
    {
        return $this->state(fn (array $attributes): array => [
            'planned_arrival_time' => '06:30:00',
            'ends_next_day' => true,
        ]);
    }

    public function bounded(string $from = '2026-11-01', string $until = '2027-03-31'): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    }

    public function unbounded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => null,
            'valid_until' => null,
        ]);
    }
}
