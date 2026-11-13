<?php

namespace Database\Factories;

use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteSchedule>
 */
class RouteScheduleFactory extends Factory
{
    protected $model = RouteSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_pattern_id' => RoutePattern::factory(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => null,
            'valid_until' => null,
        ];
    }
}
