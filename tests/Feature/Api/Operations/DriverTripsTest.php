<?php

use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;

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
});

describe('Driver Assigned Trips', function (): void {
    it('lists trips assigned exclusively to the authenticated driver across companies', function (): void {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $company = createCompany();
        $driverA = Driver::factory()->for($company)->create(['user_id' => $userA->id]);
        $driverB = Driver::factory()->for($company)->create(['user_id' => $userB->id]);

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
});
