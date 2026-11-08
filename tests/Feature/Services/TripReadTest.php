<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Trip;
use App\Services\TripReadService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('lists all trip states and archived history only within the selected company without writes', function (): void {
    Http::preventStrayRequests();
    $company = createCompany();
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
    grantShield($actor, ['ViewAny:Trip'], $company);
    $scheduled = Trip::factory()->for($company)->create(['service_date' => '2026-10-26']);
    $started = Trip::factory()->for($company)->inProgress()->create(['service_date' => '2026-10-27']);
    $completed = Trip::factory()->for($company)->completed()->create(['service_date' => '2026-10-28']);
    $cancelled = Trip::factory()->for($company)->cancelled()->create(['service_date' => '2026-10-29']);
    $cancelled->delete();
    Trip::factory()->create();
    $before = Trip::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all();

    $rows = app(TripReadService::class)->listFor($actor, $company, '2026-10-26', '2026-10-29');

    expect($rows->pluck('id')->all())->toBe([$scheduled->id, $started->id, $completed->id, $cancelled->id])
        ->and($rows->pluck('status')->all())->toBe(['scheduled', 'in_progress', 'completed', 'cancelled'])
        ->and($rows->last()['deleted_at'])->not->toBeNull()
        ->and(Trip::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);

    $company->delete();
    expect(app(TripReadService::class)->listFor($actor, $company, '2026-10-26', '2026-10-29')->pluck('id')->all())
        ->toBe($rows->pluck('id')->all());
    Http::assertNothingSent();
});

test('returns the minimal history projection with UTC timestamps and the saved snapshot', function (): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->completed()->create();
    $actor = createUserWithRole(UserRole::SuperAdmin);

    $row = app(TripReadService::class)->listFor($actor, $trip->company, '2026-10-26', '2026-10-26')->sole();

    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'status', 'service_date', 'scheduled_departure_at', 'scheduled_arrival_at',
        'actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason',
        'deleted_at', 'route_pattern_id', 'route_schedule_id', 'company', 'bus', 'driver', 'planned_route_snapshot',
    ])
        ->and($row['scheduled_departure_at'])->toBe('2026-10-26T12:30:00.000000Z')
        ->and($row['actual_arrival_at'])->toBe('2026-10-26T13:30:00.000000Z')
        ->and($row['service_date'])->toBe('2026-10-26')
        ->and($row['cancelled_at'])->toBeNull()
        ->and($row['company'])->toBe(['id' => $trip->company_id, 'name' => $trip->company->legal_name, 'deleted_at' => null])
        ->and($row['driver'])->toBe(['id' => $trip->driver_id, 'user_id' => $trip->driver->user_id, 'deleted_at' => null])
        ->and($row['bus'])->toBe([
            'id' => $trip->bus_id, 'country_code' => $trip->bus->country_code,
            'plate_number' => $trip->bus->plate_number, 'unit_number' => $trip->bus->unit_number, 'deleted_at' => null,
        ])
        ->and($row['planned_route_snapshot'])->toBe($trip->fresh()->planned_route_snapshot)
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($trip->driver->license, $trip->driver->salary_amount);
    Http::assertNothingSent();
});

test('orders same-day trips by planned departure and then UUID', function (): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $later = Trip::factory()->for($company)->create([
        'scheduled_departure_at' => '2026-10-26 14:30:00+00:00', 'scheduled_arrival_at' => '2026-10-26 15:30:00+00:00',
    ]);
    $second = Trip::factory()->for($company)->create(['id' => '00000000-0000-4000-8000-000000000002']);
    $first = Trip::factory()->for($company)->create(['id' => '00000000-0000-4000-8000-000000000001']);

    expect(app(TripReadService::class)->listFor($actor, $company, '2026-10-26', '2026-10-26')->pluck('id')->all())
        ->toBe([$first->id, $second->id, $later->id]);
});

test('restricts a driver to their assigned history even with read permission and archived parents', function (): void {
    Http::preventStrayRequests();
    $own = Trip::factory()->withEligibleDriver()->create();
    $company = $own->company;
    $actor = $own->driver->user;
    grantShield($actor, ['ViewAny:Trip'], $company);
    Trip::factory()->for($company)->create();
    Trip::factory()->create();
    $own->driver->delete();
    $own->bus->delete();
    $own->delete();
    $company->delete();

    $rows = app(TripReadService::class)->listFor($actor, $company, '2026-10-26', '2026-10-26');

    expect($rows->pluck('id')->all())->toBe([$own->id])
        ->and($rows->first()['driver']['id'])->toBe($own->driver_id)
        ->and($rows->first()['bus']['deleted_at'])->not->toBeNull()
        ->and($rows->first()['company']['deleted_at'])->not->toBeNull();
    Http::assertNothingSent();
});

test('filters inclusive local service dates instead of actual UTC dates or creation dates', function (): void {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
    $company = createCompany();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $before = Trip::factory()->for($company)->create(['service_date' => '2026-10-25']);
    $first = Trip::factory()->for($company)->create(['service_date' => '2026-10-26']);
    $last = Trip::factory()->for($company)->inProgress()->create(['service_date' => '2026-10-27']);
    Trip::factory()->for($company)->create(['service_date' => '2026-10-28']);
    Trip::query()->whereKey($last->id)->update(['actual_departure_at' => '2026-10-28 01:00:00+00:00']);
    $service = app(TripReadService::class);

    expect($service->listFor($actor, $company, '2026-10-26', '2026-10-27')->pluck('id')->all())->toBe([$first->id, $last->id])
        ->and($service->listFor($actor, $company, '2026-10-25', '2026-10-25')->pluck('id')->all())->toBe([$before->id])
        ->and($service->listFor($actor, $company, '2026-11-08', '2026-11-08'))->toBeEmpty();
    Http::assertNothingSent();
});

test('rejects invalid history date ranges with field errors', function (string $from, string $until, string $field): void {
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $company = createCompany();

    expect(fn () => app(TripReadService::class)->listFor($actor, $company, $from, $until))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
})->with([
    ['', '2026-10-26', 'from_date'], ['2026-10-26', '', 'until_date'],
    ['2026-02-30', '2026-10-26', 'from_date'], ['2026-10-26', '2026-02-30', 'until_date'],
    ['26/10/2026', '2026-10-26', 'from_date'], ['2026-10-26', '2026-10-26T00:00:00Z', 'until_date'],
    ['2026-10-27', '2026-10-26', 'until_date'],
]);

test('authorizes before validating and restores permission context after history outcomes', function (string $outcome): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    if ($outcome !== 'denied') {
        grantShield($actor, ['ViewAny:Trip'], $company);
    }
    if ($outcome === 'revoked') {
        $actor->delete();
    }
    $previousTeam = getPermissionsTeamId();
    $otherCompany = createCompany();
    try {
        setPermissionsTeamId($otherCompany->id);
        $actor->load('roles', 'permissions');
        $read = fn () => app(TripReadService::class)->listFor($actor, $company, $outcome === 'success' ? '2026-10-26' : 'invalid', '2026-10-26');
        if ($outcome === 'success') {
            expect($read())->toBeEmpty();
        } else {
            expect($read)->toThrow($outcome === 'invalid' ? ValidationException::class : AuthorizationException::class);
        }
        expect(getPermissionsTeamId())->toBe($otherCompany->id)
            ->and($actor->relationLoaded('roles'))->toBeFalse()
            ->and($actor->relationLoaded('permissions'))->toBeFalse();
    } finally {
        setPermissionsTeamId($previousTeam);
    }
})->with(['success', 'denied', 'invalid', 'revoked']);

test('reads the saved trip detail after catalogue edits and parent archival', function (string $role): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withEligibleDriver()->completed()->create();
    $company = $trip->company;
    $actor = match ($role) {
        'driver' => $trip->driver->user,
        'super admin' => createUserWithRole(UserRole::SuperAdmin),
        default => createUserWithRole(UserRole::Admin, $company),
    };
    if ($role === 'admin') {
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['View:Trip'], $company);
    }
    $snapshot = $trip->planned_route_snapshot;
    $pattern = $trip->pattern;
    $route = $pattern->route;
    $stops = $pattern->stopOccurrences()->with('stop')->get()->pluck('stop');
    $pattern->update(['name' => 'Changed pattern']);
    $route->update(['name' => 'Changed route']);
    $stops->first()->update(['name' => 'Changed stop']);
    $pattern->stopOccurrences()->get()->each->delete();
    foreach ($stops as $stop) {
        $stop->delete();
    }
    $pattern->delete();
    $route->delete();
    $trip->bus->delete();
    if ($role !== 'driver') {
        $trip->driver->user->delete();
    }
    $trip->driver->delete();
    $company->update(['timezone' => 'Asia/Tokyo']);
    $company->delete();
    $trip->delete();
    $before = $trip->fresh()->getRawOriginal();

    $row = app(TripReadService::class)->findFor($actor, $company, $trip->id);

    expect($row['id'])->toBe($trip->id)
        ->and($row['planned_route_snapshot'])->toBe($snapshot)
        ->and($row['status'])->toBe('completed')
        ->and($row['actual_departure_at'])->toBe('2026-10-26T12:30:00.000000Z')
        ->and($row['actual_arrival_at'])->toBe('2026-10-26T13:30:00.000000Z')
        ->and($row['company']['deleted_at'])->not->toBeNull()
        ->and($row['driver']['deleted_at'])->not->toBeNull()
        ->and($row['bus']['deleted_at'])->not->toBeNull()
        ->and($row['deleted_at'])->not->toBeNull()
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['admin', 'driver', 'super admin']);

test('hides inaccessible trip details with the same not-found outcome as missing trips', function (string $scenario, string $role): void {
    $own = Trip::factory()->withEligibleDriver()->create();
    $company = $own->company;
    $actor = match ($role) {
        'driver' => $own->driver->user,
        'super admin' => createUserWithRole(UserRole::SuperAdmin),
        default => createUserWithRole(UserRole::Admin, $company),
    };
    if ($role === 'admin') {
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    }
    if ($role !== 'super admin') {
        grantShield($actor, ['View:Trip'], $company);
    }
    $id = match ($scenario) {
        'foreign' => Trip::factory()->create()->id,
        'unassigned' => Trip::factory()->for($company)->create()->id,
        default => '00000000-0000-4000-8000-000000000099',
    };

    expect(fn () => app(TripReadService::class)->findFor($actor, $company, $id))
        ->toThrow(function (ModelNotFoundException $exception) use ($id): void {
            expect($exception->getModel())->toBe(Trip::class)
                ->and($exception->getIds())->toBe([$id])
                ->and($exception->getMessage())->toBe('No query results for model [App\\Models\\Trip] '.$id);
        });
})->with([
    ['missing', 'admin'], ['foreign', 'admin'], ['missing', 'super admin'], ['foreign', 'super admin'],
    ['missing', 'driver'], ['foreign', 'driver'], ['unassigned', 'driver'],
]);

test('validates the trip identifier before querying PostgreSQL', function (string $id): void {
    $company = createCompany();
    $actor = createUserWithRole(UserRole::SuperAdmin);

    expect(fn () => app(TripReadService::class)->findFor($actor, $company, $id))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('trip_id');
        });
})->with(['', 'invalid', "' OR TRUE --", ' 00000000-0000-4000-8000-000000000099']);

test('restores permission context and requires detail permission before validating an identifier', function (string $outcome): void {
    $trip = Trip::factory()->create();
    $company = $trip->company;
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    grantShield($actor, [$outcome === 'denied' ? 'ViewAny:Trip' : 'View:Trip'], $company);
    if ($outcome === 'revoked') {
        $actor->fresh()->delete();
    }
    $previousTeam = getPermissionsTeamId();
    $otherCompany = createCompany();
    try {
        setPermissionsTeamId($otherCompany->id);
        $actor->load('roles', 'permissions');
        $id = match ($outcome) {
            'success' => $trip->id,
            'missing' => '00000000-0000-4000-8000-000000000099',
            default => 'invalid',
        };
        $read = fn () => app(TripReadService::class)->findFor($actor, $company, $id);
        if ($outcome === 'success') {
            expect($read()['id'])->toBe($trip->id);
        } else {
            expect($read)->toThrow(match ($outcome) {
                'invalid' => ValidationException::class,
                'missing' => ModelNotFoundException::class,
                default => AuthorizationException::class,
            });
        }
        expect(getPermissionsTeamId())->toBe($otherCompany->id)
            ->and($actor->relationLoaded('roles'))->toBeFalse()
            ->and($actor->relationLoaded('permissions'))->toBeFalse();
    } finally {
        setPermissionsTeamId($previousTeam);
    }
})->with(['success', 'denied', 'invalid', 'missing', 'revoked']);

test('excludes private fields and values from the actual nested history response', function (string $operation): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->create();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $driver = $trip->driver;
    $user = $driver->user;
    $privateUser = $user->makeVisible(['password', 'remember_token'])->toArray();
    $privateDriver = $driver->makeVisible(['salary_amount', 'salary_currency', 'salary_period'])->toArray();
    $expectedSnapshot = $trip->fresh()->planned_route_snapshot;
    $snapshot = $expectedSnapshot;
    $snapshot['driver'] = $privateDriver;
    $snapshot['user'] = $privateUser;
    $snapshot['route_geometry']['properties'] = ['credentials' => $privateUser];
    $snapshot['occurrences'][0]['driver'] = $privateDriver;
    $snapshot['occurrences'][0]['user'] = $privateUser;
    Trip::query()->whereKey($trip->id)->update(['planned_route_snapshot' => $snapshot]);
    $before = $trip->fresh()->getRawOriginal();
    $service = app(TripReadService::class);

    $row = $operation === 'list'
        ? $service->listFor($actor, $trip->company, '2026-10-26', '2026-10-26')->sole()
        : $service->findFor($actor, $trip->company, $trip->id);

    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'status', 'service_date', 'scheduled_departure_at', 'scheduled_arrival_at',
        'actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason', 'deleted_at',
        'route_pattern_id', 'route_schedule_id', 'company', 'bus', 'driver', 'planned_route_snapshot',
    ]);
    expect($row['company'])->toBe(['id' => $trip->company_id, 'name' => $trip->company->legal_name, 'deleted_at' => null]);
    expect($row['driver'])->toBe(['id' => $driver->id, 'user_id' => $driver->user_id, 'deleted_at' => null]);
    expect(array_keys($row['bus']))->toEqualCanonicalizing(['id', 'country_code', 'plate_number', 'unit_number', 'deleted_at']);
    expect($row['planned_route_snapshot'])->toBe($expectedSnapshot);
    expect(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->not->toContain(
        '"salary_amount"', '"salary_currency"', '"salary_period"', '"password"', '"remember_token"',
        '"email"', '"license"', '"credentials"', '"user"',
        $driver->salary_amount, $driver->license, $user->email, $user->getRawOriginal('password'), $user->remember_token,
    );
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['list', 'detail']);

test('preserves repeated occurrences nulls and the original revision in history reads', function (string $operation): void {
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $pattern = RoutePattern::factory()->for(Route::factory()->for($company))->withSavedPlan()->create();
    $occurrences = $pattern->stopOccurrences()->get();
    $occurrences[1]->update(['minutes_from_start' => 15]);
    $last = RoutePatternStop::factory()->for($pattern, 'pattern')->create([
        'stop_id' => $occurrences[0]->stop_id, 'stop_sequence' => 3, 'minutes_from_start' => null,
    ]);
    $pattern->refresh()->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01], [-84, 10]]],
        'distance_meters' => '3000.00', 'driving_duration_seconds' => '600.00',
        'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0100000,10.0100000;-84.0000000,10.0000000'),
        'routing_leg_distances' => null,
    ]);
    $trip = Trip::factory()->for($company)->create(['route_pattern_id' => $pattern->id]);
    $expectedSnapshot = $trip->fresh()->planned_route_snapshot;
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $service = app(TripReadService::class);

    $row = $operation === 'list'
        ? $service->listFor($actor, $company, '2026-10-26', '2026-10-26')->sole()
        : $service->findFor($actor, $company, $trip->id);

    expect($row['planned_route_snapshot'])->toBe($expectedSnapshot)
        ->and($row['planned_route_snapshot']['routing_leg_distances'])->toBeNull()
        ->and(array_column($row['planned_route_snapshot']['occurrences'], 'occurrence_id'))
        ->toBe([$occurrences[0]->id, $occurrences[1]->id, $last->id])
        ->and(array_column($row['planned_route_snapshot']['occurrences'], 'stop_id'))
        ->toBe([$occurrences[0]->stop_id, $occurrences[1]->stop_id, $occurrences[0]->stop_id])
        ->and(array_column($row['planned_route_snapshot']['occurrences'], 'minutes_from_start'))->toBe([null, 15, null]);
    Http::assertNothingSent();
})->with(['list', 'detail']);
