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
}
