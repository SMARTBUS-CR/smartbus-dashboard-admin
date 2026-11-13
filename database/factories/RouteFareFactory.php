<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RouteFare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteFare>
 */
class RouteFareFactory extends Factory
{
    protected $model = RouteFare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => null,
            'valid_until' => null,
        ];
    }
}
