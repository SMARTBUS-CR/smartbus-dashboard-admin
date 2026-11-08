<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Trip;
use App\Services\TripReadService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
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
