<?php

namespace Database\Factories;

use App\Models\FavoriteRoute;
use App\Models\Route;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FavoriteRoute>
 */
class FavoriteRouteFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<FavoriteRoute>
     */
    protected $model = FavoriteRoute::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'route_id' => Route::factory(),
            'label' => fake()->optional()->randomElement(['Home', 'Work', 'University', 'Gym']),
        ];
    }
}
