<?php

namespace Database\Factories;

use App\Models\CompanyUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Minimal factory for the cross-database company_users pivot (pgsql side).
 *
 * @extends Factory<CompanyUser>
 */
class CompanyUserFactory extends Factory
{
    protected $model = CompanyUser::class;

    public function definition(): array
    {
        return [
            'company_id' => Str::uuid()->toString(),
            'user_id' => Str::uuid()->toString(),
        ];
    }
}
