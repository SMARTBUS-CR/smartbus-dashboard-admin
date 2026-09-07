<?php

namespace Database\Factories;

use App\Models\GpsLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<GpsLocation>
 */
class GpsLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $latitude = fake()->randomFloat(7, -4.5, 1.7);
        $longitude = fake()->randomFloat(7, -81.1, -75.1);

        return [
            'trip_id' => null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'speed_kmh' => fake()->randomFloat(2, 0, 90),
            'recorded_at' => Carbon::now(),
            'location' => sprintf('POINT(%F %F)', $longitude, $latitude),
        ];
    }
}
