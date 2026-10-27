<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Permission;
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

        $demoCompany = Company::query()->where('slug', 'smartbus-demo')->sole();
        expect($demoCompany->buses()->count())->toBe(3)
            ->and(Permission::query()->where('guard_name', 'web')->where('name', 'like', '%:Bus')
                ->orderBy('name')->pluck('name')->all())->toBe([
                    'Create:Bus', 'Update:Bus', 'View:Bus', 'ViewAny:Bus',
                ]);

        $edited = $demoCompany->buses()->where('unit_number', 'DEMO-001')->sole();
        $edited->update(['capacity' => 55, 'unit_number' => 'CUSTOM-001', 'plate_number' => 'CUSTOM-PLATE']);
        $archived = $demoCompany->buses()->where('unit_number', 'DEMO-002')->sole();
        $archived->delete();
        $before = Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $permissionIds = Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all();
        expect(Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('name')->all())
            ->toBe(['Create:Trip', 'Update:Trip']);
        $tripPermissionIds = Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('id')->all();

        $this->seed(DatabaseSeeder::class);

        expect(Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
            ->and(Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all())->toBe($permissionIds)
            ->and($company->routes()->count())->toBe(3)
            ->and($company->stops()->count())->toBe(7);
        $this->assertSoftDeleted($archived);
        $this->assertDatabaseCount('buses', 3);
        expect(Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('id')->all())->toBe($tripPermissionIds);
        $this->assertDatabaseCount('trips', 0);

        Http::assertNothingSent();
    });
});
