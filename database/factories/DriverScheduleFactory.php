<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\DriverSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverSchedule>
 */
class DriverScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'ends_next_day' => false,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }

    public function sameDay(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_time' => '08:00:00', 'end_time' => '16:00:00', 'ends_next_day' => false,
        ]);
    }

    public function overnight(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_time' => '22:00:00', 'end_time' => '06:00:00', 'ends_next_day' => true,
        ]);
    }

    public function fullDay(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_time' => '08:00:00', 'end_time' => '08:00:00', 'ends_next_day' => true,
        ]);
    }

    public function bounded(string $from = '2026-11-01', string $until = '2026-11-30'): static
    {
        return $this->state(fn (array $attributes): array => ['valid_from' => $from, 'valid_until' => $until]);
    }

    public function unbounded(): static
    {
        return $this->state(fn (array $attributes): array => ['valid_from' => null, 'valid_until' => null]);
    }
}
