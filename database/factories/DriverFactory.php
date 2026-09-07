<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::uuid(),
            'company_id' => (string) Str::uuid(),
            'license' => fake()->bothify('LIC-####-??'),
            'status' => 'active',
        ];
    }
}
