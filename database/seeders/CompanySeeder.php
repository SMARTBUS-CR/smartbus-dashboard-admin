<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection as SupportCollection;

use function count;

class CompanySeeder extends Seeder
{
    private const DEMO_SLUG = 'smartbus-demo';

    private const EXTRA_COMPANIES = 9;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('  CompanySeeder skipped in production.');

            return;
        }

        $this->createCompany(self::DEMO_SLUG,
            Company::factory()->forCountry('CR')->raw([
                'slug' => self::DEMO_SLUG,
                'legal_name' => 'Transportes SmartBus Demo',
                'trade_name' => 'SmartBus Demo',
                'phone' => '+506 8888 8888',
                'email' => 'info@smartbus.com',
                'address' => 'Av. Principal y Terminal Terrestre',
            ]),
            UserRole::Admin
        );

        $missing = self::EXTRA_COMPANIES - Company::where('slug', '!=', self::DEMO_SLUG)->count();
        if ($missing > 0) {
            $this->command?->info('  Creating '.$missing.' additional companies...');

            $companies = Company::factory($missing)->create();
            $this->attachToUser($companies, UserRole::Admin);
        }

        $this->command?->info('  Company seeding completed.');
    }

    /**
     * Attach the given company or companies to a super-admin or company-admin user,
     * prioritizing super-admins if both roles exist.
     *
     * @param  Company|SupportCollection|iterable  $company  The company or companies to attach.
     * @param  UserRole|null  $userRole  The role of the user to attach to (defaults to super-admin if not specified).
     */
    private function attachToUser(Company|SupportCollection|iterable $company, ?UserRole $userRole = null): void
    {
        $userRole ??= UserRole::Admin;
        $email = match ($userRole) {
            UserRole::SuperAdmin => 'admin@superadmin.com',
            UserRole::Admin => 'admin@company.com',
            default => 'admin@company.com',
        };

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->command?->error('  User for role '.$userRole->value.' not found. Run the Auth seeders first.');

            return;
        }

        $companyIds = $company instanceof Company
            ? [$company->id]
            : collect($company)->pluck('id')->toArray();

        $user->companies()->syncWithoutDetaching($companyIds);

        foreach ($companyIds as $companyId) {
            setPermissionsTeamId($companyId);

            // Create or find the role for the user in the context of the company
            $adminRole = Role::firstOrCreate(
                ['name' => UserRole::Admin->value, 'guard_name' => 'web', 'company_id' => $companyId],
                ['display_name' => 'Administrador']
            );

            // Create or find the driver role for the company
            Role::firstOrCreate(
                ['name' => UserRole::Driver->value, 'guard_name' => 'web', 'company_id' => $companyId],
                ['display_name' => 'Conductor']
            );

            // Spatie caches roles and permissions, so we need to clear them before assigning a new role.
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole($adminRole);
        }

        setPermissionsTeamId(null);

        $this->command?->info('  Attached '.count($companyIds).' company(ies) to user '.$user->email.'.');
    }

    /**
     * Create a company with the given slug and attributes, and attach it to a user with the specified role.
     *
     * @param  string  $slug  The slug for the company.
     * @param  array  $attributes  The attributes to set on the company.
     * @param  UserRole|null  $userRole  The role of the user to attach to (defaults to super-admin if not specified).
     * @return Company The created or restored company.
     */
    private function createCompany(string $slug, array $attributes, ?UserRole $userRole = null): Company
    {
        $company = Company::withTrashed()->firstOrCreate(['slug' => $slug],
            Company::factory()->forCountry('CR')->raw([
                'slug' => $slug,
                ...$attributes,
            ]),
        );

        if ($company->trashed()) {
            $company->restore();
        }

        $this->attachToUser($company, $userRole);

        return $company;
    }
}
