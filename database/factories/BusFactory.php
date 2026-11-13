<?php

namespace Database\Factories;

use App\Models\Bus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bus>
 */
class BusFactory extends Factory
{
    protected $model = Bus::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'country_code' => fake()->randomElement(array_keys(config('countries'))),
            'plate_number' => 'BUS-'.fake()->unique()->numerify('########'),
            'unit_number' => 'UNIT-'.fake()->unique()->numerify('########'),
            'capacity' => fake()->numberBetween(1, 100),
            'brand' => null,
            'model' => null,
            'year' => null,
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function archived(): static
    {
        return $this->afterCreating(function (Bus $bus): void {
            $bus->delete();
        });
    }
}
