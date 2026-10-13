<?php

namespace Database\Factories;

use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutePatternStop>
 */
class RoutePatternStopFactory extends Factory
{
    protected $model = RoutePatternStop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_pattern_id' => RoutePattern::factory(),

            'stop_id' => function (array $attributes): string {
                $pattern = RoutePattern::query()
                    ->with('route')
                    ->findOrFail($attributes['route_pattern_id']);

                return Stop::factory()->create([
                    'company_id' => $pattern->route->company_id,
                ])->getKey();
            },

            'stop_sequence' => 1,
            'minutes_from_start' => null,
        ];
    }
}
