<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;

function grantDriverApiAccess(User $user, Company $company): void
{
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    $previousTeam = getPermissionsTeamId();
    try {
        setPermissionsTeamId($company->id);
        $user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
    } finally {
        setPermissionsTeamId($previousTeam);
    }
}

describe('Driver Profile Catalogue', function (): void {
    it('lists employment profiles belonging exclusively to the authenticated driver', function (): void {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $company1 = createCompany();
        $company2 = createCompany();

        // User A has driver profiles in Company 1 and Company 2
        $driverA1 = Driver::factory()->for($company1)->create([
            'user_id' => $userA->id,
            'license' => 'LIC-A1-001',
            'salary_amount' => '500000.00',
        ]);
        $driverA2 = Driver::factory()->for($company2)->create([
            'user_id' => $userA->id,
            'license' => 'LIC-A2-002',
            'salary_amount' => '600000.00',
        ]);

        // User B has a driver profile in Company 1
        $driverB = Driver::factory()->for($company1)->create([
            'user_id' => $userB->id,
            'license' => 'LIC-B-999',
            'salary_amount' => '450000.00',
        ]);

        $this->actingAs($userA);

        $response = $this->getJson('/api/v1/operations/driver/profiles');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'user_id',
                            'company_id',
                            'license',
                            'license_expires_at',
                            'status',
                            'employment_starts_on',
                            'employment_ends_on',
                        ],
                    ],
                ],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($driverA1->id, $driverA2->id)
            ->and($ids)->not->toContain($driverB->id);
    });

    it('filters driver profiles by company id', function (): void {
        $user = User::factory()->create();
        $company1 = createCompany();
        $company2 = createCompany();

        $driver1 = Driver::factory()->for($company1)->create(['user_id' => $user->id]);
        $driver2 = Driver::factory()->for($company2)->create(['user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/operations/driver/profiles?filter[company_id]='.$company1->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$driver1->id]);
    });

    it('hides salary information from driver profile api responses', function (): void {
        $user = User::factory()->create();
        $company = createCompany();
        $driver = Driver::factory()->for($company)->create([
            'user_id' => $user->id,
            'salary_amount' => '750000.00',
            'salary_currency' => 'CRC',
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/operations/driver/profiles');

        $response->assertOk();
        $attributes = $response->json('data.0.attributes');
        expect($attributes)->not->toHaveKey('salary_amount')
            ->and($attributes)->not->toHaveKey('salary_currency')
            ->and($attributes)->not->toHaveKey('salary_period');
    });

    it('keeps filters and page size in profile links and breaks equal statuses by UUID', function (): void {
        $user = User::factory()->create();
        $profiles = collect(range(1, 3))->map(fn (): Driver => Driver::factory()
            ->for(createCompany())
            ->create(['user_id' => $user->id]));
        $expected = $profiles->pluck('id')->sort()->values()->all();
        $this->actingAs($user);
        $uri = '/api/v1/operations/driver/profiles?'.http_build_query([
            'filter' => ['status' => 'active'], 'sort' => 'status', 'page' => ['size' => 1],
        ]);
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
            $seen[] = $response->json('data.0.id');
            $uri = $response->json('links.next');
        }

        expect($seen)->toBe($expected)
            ->and($uri)->toBeNull();
    });
});

describe('Driver Assigned Trips', function (): void {
    it('lists trips assigned exclusively to the authenticated driver across companies', function (): void {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $company = createCompany();
        $driverA = Driver::factory()->for($company)->create(['user_id' => $userA->id]);
        $driverB = Driver::factory()->for($company)->create(['user_id' => $userB->id]);
        grantDriverApiAccess($userA, $company);

        $tripA1 = Trip::factory()->for($company)->create([
            'driver_id' => $driverA->id,
            'service_date' => '2026-11-10',
        ]);
        $tripA2 = Trip::factory()->for($company)->inProgress()->create([
            'driver_id' => $driverA->id,
            'service_date' => '2026-11-11',
        ]);
        $tripB = Trip::factory()->for($company)->create([
            'driver_id' => $driverB->id,
            'service_date' => '2026-11-10',
        ]);

        $this->actingAs($userA);

        $response = $this->getJson('/api/v1/operations/driver/trips');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'service_date',
                            'status',
                            'scheduled_departure_at',
                            'scheduled_arrival_at',
                            'actual_departure_at',
                            'actual_arrival_at',
                            'planned_route_snapshot',
                        ],
                    ],
                ],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($tripA1->id, $tripA2->id)
            ->and($ids)->not->toContain($tripB->id);
    });

    it('filters assigned trips by status and service date', function (): void {
        $user = User::factory()->create();
        $company = createCompany();
        $driver = Driver::factory()->for($company)->create(['user_id' => $user->id]);
        grantDriverApiAccess($user, $company);

        $scheduledTrip = Trip::factory()->for($company)->create([
            'driver_id' => $driver->id,
            'service_date' => '2026-11-10',
        ]);
        $inProgressTrip = Trip::factory()->for($company)->inProgress()->create([
            'driver_id' => $driver->id,
            'service_date' => '2026-11-11',
        ]);
        $otherDateTrip = Trip::factory()->for($company)->create([
            'driver_id' => $driver->id,
            'service_date' => '2026-11-12',
        ]);

        $this->actingAs($user);

        // 1. Filter by status=scheduled
        $statusFiltered = $this->getJson('/api/v1/operations/driver/trips?filter[status]=scheduled');
        $statusFiltered->assertOk();
        $statusIds = collect($statusFiltered->json('data'))->pluck('id')->all();
        expect($statusIds)->toContain($scheduledTrip->id, $otherDateTrip->id)
            ->and($statusIds)->not->toContain($inProgressTrip->id);

        // 2. Filter by service_date=2026-11-10
        $dateFiltered = $this->getJson('/api/v1/operations/driver/trips?filter[service_date]=2026-11-10');
        $dateFiltered->assertOk();
        $dateIds = collect($dateFiltered->json('data'))->pluck('id')->all();
        expect($dateIds)->toBe([$scheduledTrip->id]);
    });

    it('rejects unauthenticated requests to driver endpoints with 401', function (): void {
        $this->getJson('/api/v1/operations/driver/profiles')->assertStatus(401);
        $this->getJson('/api/v1/operations/driver/trips')->assertStatus(401);
    });

    it('validates per_page pagination parameters returning 422 for invalid values', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Negative per_page
        $this->getJson('/api/v1/operations/driver/profiles?per_page=-5')->assertStatus(422);
        $this->getJson('/api/v1/operations/driver/trips?per_page=-1')->assertStatus(422);

        // Zero per_page
        $this->getJson('/api/v1/operations/driver/profiles?per_page=0')->assertStatus(422);
        $this->getJson('/api/v1/operations/driver/trips?per_page=0')->assertStatus(422);

        // Excessive per_page
        $this->getJson('/api/v1/operations/driver/profiles?per_page=150')->assertStatus(422);
        $this->getJson('/api/v1/operations/driver/trips?per_page=500')->assertStatus(422);
    });

    it('hides assigned trips after driver membership or role revocation', function (string $revocation): void {
        $trip = Trip::factory()->withEligibleDriver()->create()->refresh();
        $driverUser = $trip->driver->user;

        if ($revocation === 'membership') {
            CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $driverUser->id)->delete();
        } else {
            $previousTeam = getPermissionsTeamId();
            setPermissionsTeamId($trip->company_id);
            try {
                $driverUser->removeRole(UserRole::Driver->value);
            } finally {
                setPermissionsTeamId($previousTeam);
            }
        }

        $this->actingAs($driverUser);
        $this->getJson('/api/v1/operations/driver/trips')->assertOk()->assertJsonPath('data', []);
    })->with(['membership', 'role']);

    it('keeps filters and page size in trip links and breaks equal statuses by UUID', function (): void {
        $first = Trip::factory()->withEligibleDriver()->create()->refresh();
        $driverUser = $first->driver->user;
        $second = Trip::factory()->for($first->company)->create(['driver_id' => $first->driver_id, 'service_date' => '2026-10-27']);
        $third = Trip::factory()->for($first->company)->create(['driver_id' => $first->driver_id, 'service_date' => '2026-10-28']);
        $expected = [$first->id, $second->id, $third->id];
        sort($expected);
        $this->actingAs($driverUser);
        $uri = '/api/v1/operations/driver/trips?'.http_build_query([
            'filter' => ['status' => 'scheduled'], 'sort' => 'status', 'page' => ['size' => 1],
        ]);
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
            $seen[] = $response->json('data.0.id');
            $uri = $response->json('links.next');
        }

        expect($seen)->toBe($expected)
            ->and($uri)->toBeNull();
    });
});
