<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection as SupportCollection;

use function count;

class CompanySeeder extends Seeder
{
    private const DEMO_SLUG = 'smartbus-demo';

    private const ADMIN_SLUG = 'smartbus-admin';

    private const EXTRA_COMPANIES = 8;

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
        );

        $this->createCompany(self::ADMIN_SLUG,
            Company::factory()->forCountry('CR')->raw([
                'slug' => self::ADMIN_SLUG,
                'legal_name' => 'Transportes SmartBus Admin',
                'trade_name' => 'SmartBus Admin',
                'phone' => '+506 8888 8889',
                'email' => 'admin@smartbus.com',
                'address' => 'Av. Principal y Terminal Terrestre',
            ]),
            UserRole::CompanyAdmin
        );

        $missing = self::EXTRA_COMPANIES - Company::where('slug', '!=', self::DEMO_SLUG)->count();
        if ($missing > 0) {
            $this->command?->info('  Creating '.$missing.' additional companies...');

            $companies = Company::factory($missing)->create();
            $this->attachToUser($companies);
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
        $userRole ??= UserRole::SuperAdmin;
        $user = User::role($userRole)->first();

        if (! $user) {
            $this->command?->error(' No super-admin or company-admin users found. Skipping company attachment.');

            return;
        }

        // Converts any input (Company or Collection) into a clean list of IDs
        $companyIds = $company instanceof Company
            ? [$company->id]
            : collect($company)->pluck('id')->toArray();

        $user->companies()->syncWithoutDetaching($companyIds);
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
