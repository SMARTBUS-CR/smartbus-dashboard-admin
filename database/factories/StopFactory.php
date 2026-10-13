<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stop>
 */
class StopFactory extends Factory
{
    protected $model = Stop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->streetName(),
            'description' => null,
            'latitude' => fake()->latitude(8, 18),
            'longitude' => fake()->longitude(-92, -77),
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'company_id' => null,
        ]);
    }
}
