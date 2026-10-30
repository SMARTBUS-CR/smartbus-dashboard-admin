<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\Company;
use App\Models\CompanyUser;
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
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'license' => 'LICENSE-'.Str::upper(Str::random(12)),
            'license_expires_at' => '2030-12-31',
            'employment_starts_on' => '2026-10-01',
            'employment_ends_on' => null,
            'salary_amount' => '650000.00',
            'salary_currency' => 'CRC',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => DriverStatus::ACTIVE]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => DriverStatus::INACTIVE]);
    }

    public function archived(): static
    {
        return $this->afterCreating(function (Driver $driver): void {
            $driver->delete();
        });
    }

    /** Opt in to membership fixtures without granting permissions or restoring access. */
    public function withMembership(): static
    {
        return $this->afterCreating(function (Driver $driver): void {
            if (! CompanyUser::withTrashed()->where('company_id', $driver->company_id)->where('user_id', $driver->user_id)->exists()) {
                CompanyUser::factory()->create(['company_id' => $driver->company_id, 'user_id' => $driver->user_id]);
            }
        });
    }
}
