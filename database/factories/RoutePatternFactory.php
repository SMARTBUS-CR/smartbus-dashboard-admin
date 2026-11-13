<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutePattern>
 */
class RoutePatternFactory extends Factory
{
    protected $model = RoutePattern::class;

    public function withSavedPlan(): static
    {
        return $this->afterCreating(function (RoutePattern $pattern): void {
            foreach ([[10, -84], [10.01, -84.01]] as $index => [$latitude, $longitude]) {
                $stop = Stop::factory()->create([
                    'company_id' => $pattern->route->company_id,
                    'latitude' => $latitude, 'longitude' => $longitude,
                ]);
                RoutePatternStop::factory()->for($pattern, 'pattern')->for($stop)->create([
                    'stop_sequence' => $index + 1,
                ]);
            }

            $pattern->update([
                'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01]]],
                'distance_meters' => '1500.00', 'driving_duration_seconds' => '300.00',
                'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0100000,10.0100000'),
                'routing_leg_distances' => [1500],
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'code' => 'P-'.fake()->unique()->numerify('########'),
            'name' => fake()->words(3, true),
            'headsign' => fake()->city(),
        ];
    }
}
