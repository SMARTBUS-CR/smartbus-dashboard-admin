<?php

use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\DriverScheduleService;
use App\Services\TripManagementService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{User, Trip, array<string, mixed>} */
function reservationInputs(): array
{
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->make();
    $actor = createUserWithRole(UserRole::SuperAdmin);

    return [$actor, $trip, [
        ...$trip->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
        'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00',
    ]];
}

test('rejects another profile of the same person across companies without revealing the conflicting trip', function (string $locale, bool $archived): void {
    [$actor, $candidate, $data] = reservationInputs();
    $other = createCompany(['timezone' => 'America/Costa_Rica']);
    $profile = Driver::factory()->for($other)->create(['user_id' => $candidate->driver->user_id]);
    $existing = Trip::factory()->for($other)->for($profile, 'driver')->create()->refresh();
    if ($archived) {
        $existing->delete();
        $existing->refresh();
    }
    $before = $existing->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => app(TripManagementService::class)->schedule($actor, $candidate->company, $data))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['driver_id' => [__('The selected departure or resource conflicts with an existing trip.')]]);
        });
    expect($existing->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
})->with(['en', 'es'])->with(['visible trip' => false, 'archived trip' => true]);

test('preserves assigned trips when employment changes would make the driver ineligible', function (string $operation, bool $archived): void {
    [$actor, $fixture, $data] = reservationInputs();
    $trip = app(TripManagementService::class)->schedule($actor, $fixture->company, $data);
    if ($archived) {
        $trip->delete();
    }
    $driver = $trip->driver->refresh();
    $driver->update(['employment_starts_on' => '2026-10-01']);
    $driver->refresh();
    $before = $driver->getRawOriginal();
    $service = app(DriverManagementService::class);

    expect(fn () => match ($operation) {
        'deactivate' => $service->setActive($actor, $trip->company, $driver, false),
        'archive' => $service->archive($actor, $trip->company, $driver),
        default => $service->updateEmployment($actor, $trip->company, $driver, [$operation => '2026-10-25']),
    })->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toBe(['driver' => [__('This change would invalidate an assigned trip.')]]);
    });

    expect($driver->fresh()->getRawOriginal())->toBe($before);
    $this->assertModelExists($trip);
})->with(['deactivate', 'archive', 'license_expires_at', 'employment_ends_on'])->with(['visible trip' => false, 'archived trip' => true]);

test('rolls back an availability mutation that would uncover an assigned trip', function (string $operation, bool $archived): void {
    [$actor, $fixture, $data] = reservationInputs();
    $trip = app(TripManagementService::class)->schedule($actor, $fixture->company, $data);
    if ($archived) {
        $trip->delete();
    }
    $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->firstOrFail();
    $before = $schedule->getRawOriginal();
    $service = app(DriverScheduleService::class);

    expect(fn () => $operation === 'delete'
        ? $service->delete($actor, $trip->company, $trip->driver, $schedule)
        : $service->update($actor, $trip->company, $trip->driver, $schedule, ['end_time' => '06:45', 'ends_next_day' => false]))
        ->toThrow(ValidationException::class);

    expect($schedule->fresh()->getRawOriginal())->toBe($before);
    $this->assertModelExists($trip);
})->with(['delete', 'update'])->with(['visible trip' => false, 'archived trip' => true]);

test('allows salary and availability changes that preserve assigned coverage', function (): void {
    [$actor, $fixture, $data] = reservationInputs();
    $trip = app(TripManagementService::class)->schedule($actor, $fixture->company, $data);
    $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->firstOrFail();

    $updated = app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $trip->driver, ['salary_amount' => '700000.00']);
    app(DriverScheduleService::class)->update($actor, $trip->company, $trip->driver, $schedule, ['end_time' => '08:00', 'ends_next_day' => false]);

    expect($updated->salary_amount)->toBe('700000.00');
    expect($schedule->fresh()->end_time)->toBe('08:00:00');
    $this->assertModelExists($trip);
});

test('permits exact adjacency across profiles of the same person', function (): void {
    [$actor, $candidate, $data] = reservationInputs();
    $other = createCompany(['timezone' => 'America/Costa_Rica']);
    $profile = Driver::factory()->for($other)->create(['user_id' => $candidate->driver->user_id]);
    Trip::factory()->for($other)->for($profile, 'driver')->create();
    $candidate->schedule->update(['departure_time' => '07:30:00']);
    $data['arrival_at'] = '2026-10-26T08:30:00-06:00';

    $trip = app(TripManagementService::class)->schedule($actor, $candidate->company, $data);

    $this->assertModelExists($trip);
    $this->assertDatabaseCount('trips', 2);
});
