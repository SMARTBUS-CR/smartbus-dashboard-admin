<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

describe('Database Seeding', function (): void {
    test('includes Sarapiqui routes and provisions the company roles', function (): void {
        Http::fake();
        Http::preventStrayRequests();

        User::factory()->create([
            'email' => 'admin@company.com',
        ]);

        $this->seed(DatabaseSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        expect($company->routes()->count())->toBe(3)
            ->and($company->stops()->count())->toBe(7);

        $roles = Role::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        expect($roles->pluck('name')->all())->toBe([
            UserRole::Admin->value,
            UserRole::Driver->value,
        ]);

        foreach ($roles as $role) {
            expect($role->color)->toMatch('/^#[0-9a-fA-F]{6}$/');
        }

        Http::assertNothingSent();
    });
});
