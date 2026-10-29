<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;

trait WithDriverRegistration
{
    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function validDriverEmployment(array $overrides = []): array
    {
        return array_replace([
            'license' => 'LICENSE-REGISTRATION',
            'license_expires_at' => '2030-12-31',
            'employment_starts_on' => '2026-10-01',
            'employment_ends_on' => null,
            'salary_amount' => '650000.25',
            'salary_currency' => 'CRC',
            'salary_period' => 'monthly',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function validDriverIdentity(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Driver',
            'email' => 'new-driver@example.test',
            'password' => 'N7v!qL2#rX9@kP4',
            'password_confirmation' => 'N7v!qL2#rX9@kP4',
        ], $overrides);
    }

    protected function createWorkforceAdministrator(Company $company): User
    {
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['Create:Driver'], $company);

        return $actor;
    }
}
