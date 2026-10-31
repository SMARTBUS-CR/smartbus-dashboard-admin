<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DriverSchedule;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use App\Services\TripPlanSnapshotService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TripSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

/** @return array{Company, Trip} */
function demoTripResources(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['slug' => 'smartbus-demo', 'timezone' => 'America/Costa_Rica']);
    $fixture = Trip::factory()->for($company)->withDriverAvailability()->make(['service_date' => '2026-11-02']);

    return [$company, $fixture];
}

test('seeds a deterministic validated demo trip without creating identities or granting permissions', function (): void {
    [$company, $fixture] = demoTripResources();
    $team = createCompany()->getKey();
    $users = User::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $roles = Role::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    setPermissionsTeamId($team);

    $this->seed(TripSeeder::class);

    $trip = Trip::query()->sole();
    expect($trip->getKey())->toBe('b0700000-0000-4000-8000-000000000001');
    expect($trip->company_id)->toBe($company->getKey());
    expect($trip->bus_id)->toBe($fixture->bus_id);
    expect($trip->driver_id)->toBe($fixture->driver_id);
    expect($trip->status)->toBe(TripStatus::SCHEDULED);
    expect($trip->service_date->toDateString())->toBe('2026-11-02');
    expect($trip->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 12:30:00');
    expect($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:30:00');
    expect($trip->planned_route_snapshot['revision_hash'])->toBe(app(TripPlanSnapshotService::class)->revisionHash($trip->planned_route_snapshot));
    expect($trip->actual_departure_at)->toBeNull();
    expect($trip->actual_arrival_at)->toBeNull();
    expect(User::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($users);
    expect(Role::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($roles);
    expect(getPermissionsTeamId())->toBe($team);
    $before = $trip->getRawOriginal();
    $this->seed(TripSeeder::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
});

test('preserves replanned and terminal demo history when seeded again', function (string $state): void {
    [$company] = demoTripResources();
    $this->seed(TripSeeder::class);
    $trip = Trip::query()->sole();
    if ($state === 'replanned') {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), [
            ...$trip->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-11-02', 'arrival_at' => '2026-11-02T08:30:00-06:00',
        ]);
    } else {
        $values = $state === 'completed'
            ? ['actual_departure_at' => '2026-11-02T12:30:00Z', 'actual_arrival_at' => '2026-11-02T13:30:00Z']
            : ['cancelled_at' => '2026-11-02T12:00:00Z', 'cancellation_reason' => 'Preserved cancellation'];
        Trip::query()->whereKey($trip->getKey())->update(['status' => $state, ...$values]);
    }
    $trip->driver->delete();
    $before = $trip->fresh()->getRawOriginal();

    $this->seed(TripSeeder::class);

    expect($trip->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
})->with(['replanned', 'completed', 'cancelled']);

test('skips unavailable demo planning dependencies without partial writes', function (string $scenario): void {
    [$company, $fixture] = demoTripResources();
    match ($scenario) {
        'inactive company' => $company->update(['status' => CompanyStatus::INACTIVE]),
        'archived company' => $company->delete(),
        'inactive bus' => $fixture->bus->forceFill(['is_active' => false])->save(),
        'archived bus' => $fixture->bus->delete(),
        'inactive driver' => $fixture->driver->update(['status' => DriverStatus::INACTIVE]),
        'expired license' => $fixture->driver->update(['license_expires_at' => '2026-11-01']),
        'no availability' => DriverSchedule::query()->where('driver_id', $fixture->driver_id)->delete(),
        'unavailable plan' => $fixture->pattern->update(['route_geometry' => null, 'distance_meters' => null, 'driving_duration_seconds' => null, 'routing_points_hash' => null]),
        'wrong calendar' => $fixture->schedule->update(['day_of_week' => 2]),
    };

    $this->seed(TripSeeder::class);

    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
})->with(['inactive company', 'archived company', 'inactive bus', 'archived bus', 'inactive driver', 'expired license', 'no availability', 'unavailable plan', 'wrong calendar']);

test('skips missing demo resources with an explanation', function (): void {
    Http::preventStrayRequests();

    $this->artisan('db:seed', ['--class' => TripSeeder::class, '--force' => true])
        ->expectsOutputToContain('TripSeeder skipped: complete eligible demo planning resources are required.')
        ->assertSuccessful();

    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});

test('preserves departures already reserved by other trips', function (): void {
    [, $fixture] = demoTripResources();
    $fixture->save();
    $before = $fixture->fresh()->getRawOriginal();

    $this->seed(TripSeeder::class);

    expect($fixture->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
});

test('never inserts demo trips in production', function (): void {
    demoTripResources();
    $this->app->instance('env', 'production');

    try {
        $this->artisan('db:seed', ['--class' => TripSeeder::class, '--force' => true])
            ->expectsOutputToContain('TripSeeder skipped in production.')->assertSuccessful();
    } finally {
        $this->app->instance('env', 'testing');
    }
    $this->assertDatabaseCount('trips', 0);
});

test('database setup includes repeatable trip fixtures after their existing demo dependencies', function (): void {
    demoTripResources();
    User::factory()->create(['email' => 'admin@company.com']);

    $this->seed(DatabaseSeeder::class);

    $trip = Trip::query()->sole();
    $before = $trip->getRawOriginal();
    $this->seed(DatabaseSeeder::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
});

test('never reassigns a demo UUID already owned by another company', function (): void {
    demoTripResources();
    $existing = Trip::factory()->create(['id' => 'b0700000-0000-4000-8000-000000000001'])->refresh();
    $before = $existing->getRawOriginal();

    $this->seed(TripSeeder::class);

    expect($existing->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
});

test('uses the first eligible covered employment rather than an ineligible earlier profile', function (): void {
    [$company, $fixture] = demoTripResources();
    $fixture->driver->update(['license_expires_at' => '2026-11-01']);
    $eligible = Trip::factory()->for($company)->withDriverAvailability()->make(['service_date' => '2026-11-02']);

    $this->seed(TripSeeder::class);

    expect(Trip::query()->sole()->driver_id)->toBe($eligible->driver_id);
    Http::assertNothingSent();
});

test('propagates unexpected database errors and rolls back the demo insertion', function (): void {
    demoTripResources();
    $dispatcher = Trip::getEventDispatcher();
    Trip::setEventDispatcher(clone $dispatcher);
    Trip::creating(function (Trip $trip): void {
        $trip->planned_route_snapshot = [];
    });

    try {
        expect(fn () => $this->seed(TripSeeder::class))->toThrow(function (QueryException $exception): void {
            expect($exception->errorInfo[0])->toBe('23514');
        });
    } finally {
        Trip::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});
