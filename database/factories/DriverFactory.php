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
            'id' => (string) Str::uuid(),
            'user_id' => User::on('mysql')->value('id'),
            'company_id' => 1,
            'license' => fake()->bothify('LIC-####-??'),
            'status' => 'active',
        ];
    }
}
