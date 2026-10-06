<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Timezones by country code.
     *
     * @var array<string, string>
     */
    private const TIMEZONES_BY_COUNTRY = [
        'CR' => 'America/Costa_Rica',
        'GT' => 'America/Guatemala',
        'SV' => 'America/El_Salvador',
        'HN' => 'America/Tegucigalpa',
        'NI' => 'America/Managua',
        'PA' => 'America/Panama',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $legalName = $this->faker->company();
        $countryCode = fake()->randomElement(array_keys(self::TIMEZONES_BY_COUNTRY));

        return [
            'legal_name' => $legalName,
            'trade_name' => fake()->boolean(60) ? fake()->company() : null,
            'slug' => Str::slug($legalName).'-'.Str::lower(Str::random(6)),
            'country_code' => $countryCode,
            'legal_id' => fake()->unique()->numerify('##########'),
            'operator_number' => 'OP-'.fake()->unique()->numerify('########'),
            'phone' => '+50688888888',
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'timezone' => self::TIMEZONES_BY_COUNTRY[$countryCode],
            'status' => CompanyStatus::ACTIVE,
        ];
    }

    /**
     * Indicate that the model's status should be inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyStatus::INACTIVE,
        ]);
    }

    /**
     * Indicate that the model should be for a specific country.
     */
    public function forCountry(string $countryCode): static
    {
        $countryCode = strtoupper($countryCode);

        return $this->state(fn (array $attributes) => [
            'country_code' => $countryCode,
            'timezone' => self::TIMEZONES_BY_COUNTRY[$countryCode] ?? config('app.timezone'),
        ]);
    }
}
