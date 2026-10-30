<?php

use App\Enums\DriverStatus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

describe('Driver Factory', function (): void {
    test('reuses one identity across companies with deterministic overrides and no role provisioning', function (): void {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $companies = [createCompany(), createCompany()];
        setPermissionsTeamId($companies[0]->id);

        foreach ($companies as $company) {
            $driver = Driver::factory()->for($company)->for($user)->active()->create([
                'license' => ' demo-001 ', 'salary_amount' => '123456.78', 'salary_currency' => 'usd',
            ])->fresh();
            expect($driver->id)->toBeUuid()
                ->and($driver->company_id)->toBe($company->id)
                ->and($driver->user->id)->toBe($user->id)
                ->and($driver->status)->toBe(DriverStatus::ACTIVE)
                ->and($driver->license)->toBe('DEMO-001')
                ->and($driver->salary_amount)->toBe('123456.78')
                ->and($driver->salary_currency)->toBe('USD');
        }

        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('company_users', 0);
        expect(DB::connection('mysql')->table('model_has_roles')->count())->toBe(0)
            ->and(getPermissionsTeamId())->toBe($companies[0]->id);
        Http::assertNothingSent();
    });

    test('archives inactive employment without archiving the identity', function (): void {
        $driver = Driver::factory()->inactive()->archived()->create();

        expect($driver->status)->toBe(DriverStatus::INACTIVE)
            ->and($driver->user->trashed())->toBeFalse();
        $this->assertSoftDeleted($driver);
    });

    test('opts into membership without altering an existing membership', function (): void {
        $company = createCompany();
        $user = User::factory()->create();
        $membership = CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
        $before = $membership->fresh()->getAttributes();

        Driver::factory()->for($company)->for($user)->withMembership()->create();
        $new = Driver::factory()->withMembership()->create();

        expect($membership->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseHas('company_users', ['company_id' => $new->company_id, 'user_id' => $new->user_id, 'deleted_at' => null]);
        $this->assertDatabaseCount('company_users', 2);
    });

    test('does not restore revoked membership through the opt in state', function (): void {
        $company = createCompany();
        $user = User::factory()->create();
        $membership = CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
        $membership->delete();

        Driver::factory()->for($company)->for($user)->withMembership()->create();

        $this->assertSoftDeleted($membership);
        $this->assertDatabaseCount('company_users', 1);
    });
});
