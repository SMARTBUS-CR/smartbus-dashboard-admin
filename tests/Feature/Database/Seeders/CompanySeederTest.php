<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;

describe('Company Seeding', function (): void {
    test('assigns company access to the company admin without linking the system admin', function (): void {
        $companyAdmin = User::factory()->create([
            'email' => 'admin@company.com',
        ]);

        $systemAdmin = createUserWithRole(UserRole::SuperAdmin);

        $this->seed(CompanySeeder::class);

        $companies = Company::query()->get();

        expect($companies)->toHaveCount(10);

        foreach ($companies as $company) {
            $this->assertDatabaseHas(CompanyUser::class, [
                'company_id' => $company->getKey(),
                'user_id' => $companyAdmin->getKey(),
                'deleted_at' => null,
            ]);

            $role = Role::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('name', UserRole::Admin->value)
                ->where('guard_name', 'web')
                ->sole();

            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => $role->getKey(),
                'model_uuid' => $companyAdmin->getKey(),
                'model_type' => $companyAdmin->getMorphClass(),
                'company_id' => $company->getKey(),
            ], 'mysql');
        }

        expect(
            CompanyUser::withTrashed()
                ->where('user_id', $systemAdmin->getKey())
                ->exists(),
        )->toBeFalse();
    });
});
