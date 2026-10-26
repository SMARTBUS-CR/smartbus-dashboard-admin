<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Services\BusManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function createBusLifecycleTrip(Bus $bus, string $status): string
{
    $route = Route::factory()->for($bus->company)->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create();
    $user = createUserWithRole(UserRole::Driver, $bus->company);
    $driverId = (string) Str::uuid();
    DB::table('drivers')->insert([
        'id' => $driverId, 'company_id' => $bus->company_id, 'user_id' => $user->getKey(),
        'license' => 'LICENSE-'.Str::upper(Str::random(8)), 'license_expires_at' => '2030-01-01',
        'employment_starts_on' => '2026-01-01', 'salary_amount' => 600000, 'salary_currency' => 'CRC',
    ]);
    $tripId = (string) Str::uuid();
    DB::table('trips')->insert([
        'id' => $tripId, 'company_id' => $bus->company_id,
        'route_pattern_id' => $pattern->getKey(), 'route_schedule_id' => $schedule->getKey(),
        'bus_id' => $bus->getKey(), 'driver_id' => $driverId, 'status' => $status,
        'service_date' => '2026-10-13',
        'scheduled_departure_at' => '2026-10-13 12:00:00+00',
        'scheduled_arrival_at' => '2026-10-13 13:00:00+00',
        'actual_departure_at' => in_array($status, ['in_progress', 'completed'], true) ? '2026-10-13 12:00:00+00' : null,
        'actual_arrival_at' => $status === 'completed' ? '2026-10-13 13:00:00+00' : null,
        'cancelled_at' => $status === 'cancelled' ? '2026-10-13 11:00:00+00' : null,
        'cancellation_reason' => $status === 'cancelled' ? 'Service cancelled' : null,
        'planned_route_snapshot' => json_encode(['version' => 1, 'route_id' => $route->getKey()], JSON_THROW_ON_ERROR),
    ]);

    return $tripId;
}

test('rejects bus unavailability while scheduled or ongoing trips remain', function (string $status, string $operation, string $locale, string $message, UserRole $role): void {
    $this->travelTo(new DateTimeImmutable('2026-10-22 12:00:00+00'));
    app()->setLocale($locale);
    $bus = Bus::factory()->active()->create();
    $tripId = createBusLifecycleTrip($bus, $status);
    $actor = createUserWithRole($role, $role === UserRole::Admin ? $bus->company : null);
    if ($role === UserRole::Admin) {
        CompanyUser::create(['company_id' => $bus->company_id, 'user_id' => $actor->getKey()]);
        grantShield($actor, ['Update:Bus', 'Delete:Bus'], $bus->company);
    }
    $previousTeam = getPermissionsTeamId();
    $before = $bus->fresh()->getAttributes();
    $tripBefore = (array) DB::table('trips')->where('id', $tripId)->sole();

    try {
        $service = app(BusManagementService::class);
        if ($operation === 'archive') {
            $service->archive($actor, $bus->company, $bus->getKey());
        } else {
            $service->setActive($actor, $bus->company, $bus->getKey(), false);
        }
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['bus' => [$message]]);
        expect($bus->fresh()->getAttributes())->toBe($before);
        expect((array) DB::table('trips')->where('id', $tripId)->sole())->toBe($tripBefore);
        expect(getPermissionsTeamId())->toBe($previousTeam);
    }
})->with(['scheduled', 'in_progress'])->with(['archive', 'deactivate'])->with([
    'English' => ['en', 'Buses with scheduled or in-progress trips cannot be deactivated or archived.'],
    'Spanish' => ['es', 'No se pueden desactivar ni archivar autobuses con viajes programados o en curso.'],
])->with(['company admin' => [UserRole::Admin], 'super admin' => [UserRole::SuperAdmin]]);

test('preserves completed and cancelled trip history through bus lifecycle changes', function (string $status, string $operation): void {
    $bus = Bus::factory()->active()->create();
    $tripId = createBusLifecycleTrip($bus, $status);
    $tripBefore = (array) DB::table('trips')->where('id', $tripId)->sole();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $service = app(BusManagementService::class);

    if ($operation === 'archive') {
        $service->archive($actor, $bus->company, $bus->getKey());
        $this->assertSoftDeleted($bus);
        expect((array) DB::table('trips')->where('id', $tripId)->sole())->toBe($tripBefore);
        $result = $service->restore($actor, $bus->company, $bus->getKey());
        expect($result->trashed())->toBeFalse();
    } else {
        $result = $service->setActive($actor, $bus->company, $bus->getKey(), false);
        expect($result->is_active)->toBeFalse();
    }

    expect($result->getKey())->toBe($bus->getKey())
        ->and($result->company_id)->toBe($bus->company_id);
    expect((array) DB::table('trips')->where('id', $tripId)->sole())->toBe($tripBefore);
})->with(['completed', 'cancelled'])->with(['archive', 'deactivate']);

test('does not block reactivation when the bus has active trips', function (string $status): void {
    $bus = Bus::factory()->inactive()->create();
    $tripId = createBusLifecycleTrip($bus, $status);
    $actor = createUserWithRole(UserRole::SuperAdmin);

    $result = app(BusManagementService::class)->setActive($actor, $bus->company, $bus->getKey(), true);

    expect($result->is_active)->toBeTrue();
    $this->assertDatabaseHas('trips', ['id' => $tripId, 'bus_id' => $bus->getKey(), 'status' => $status]);
})->with(['scheduled', 'in_progress']);

test('ignores active trips assigned to other buses', function (string $operation): void {
    $bus = Bus::factory()->active()->create();
    $otherBus = Bus::factory()->for($bus->company)->active()->create();
    $tripId = createBusLifecycleTrip($otherBus, 'scheduled');
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $service = app(BusManagementService::class);

    if ($operation === 'archive') {
        $service->archive($actor, $bus->company, $bus->getKey());
        $this->assertSoftDeleted($bus);
    } else {
        $service->setActive($actor, $bus->company, $bus->getKey(), false);
        expect($bus->fresh()->is_active)->toBeFalse();
    }

    $this->assertDatabaseHas('trips', ['id' => $tripId, 'bus_id' => $otherBus->getKey(), 'status' => 'scheduled']);
})->with(['archive', 'deactivate']);
