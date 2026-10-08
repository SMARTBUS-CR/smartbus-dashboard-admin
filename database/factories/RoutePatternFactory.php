<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RoutePattern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutePattern>
 */
class RoutePatternFactory extends Factory
{
    protected $model = RoutePattern::class;

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
