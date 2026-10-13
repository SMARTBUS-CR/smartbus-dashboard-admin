<?php

namespace Database\Factories;

use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteScheduleException>
 */
class RouteScheduleExceptionFactory extends Factory
{
    protected $model = RouteScheduleException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_schedule_id' => RouteSchedule::factory(),
            'service_date' => '2026-11-02',
        ];
    }
}
