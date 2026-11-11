<?php

use App\Enums\UserRole;
use App\Models\Trip;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('preserves real execution history against direct writes deletion and replanning even for super admins', function (string $state): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $service = app(TripManagementService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    if ($state === 'cancelled') {
        $trip = $service->cancel($actor, $trip->company, $trip->id, 'Preserved reason');
    } else {
        $trip = $service->start($actor, $trip->company, $trip->id);
        if ($state === 'completed') {
            $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));
            $trip = $service->complete($actor, $trip->company, $trip->id);
        }
    }
    $before = $trip->getRawOriginal();
    foreach (['save', 'saveQuietly'] as $method) {
        $changed = $trip->fresh()->forceFill([
            'company_id' => createCompany()->id, 'status' => 'scheduled',
            'actual_departure_at' => null, 'actual_arrival_at' => null,
            'cancelled_at' => null, 'cancellation_reason' => null,
        ]);
        expect(fn () => $changed->{$method}())->toThrow(ValidationException::class)
            ->and($trip->fresh()->getRawOriginal())->toBe($before);
    }
    foreach (['forceDelete', 'forceDeleteQuietly'] as $method) {
        expect(fn () => $trip->{$method}())->toThrow(ValidationException::class);
    }
    expect(fn () => Trip::forceDestroy($trip->id))->toThrow(ValidationException::class);
    foreach (['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $trip))->toBeFalse();
    }
    expect(fn () => $service->replan($actor, $trip->company, $trip->id, []))
        ->toThrow(AuthorizationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before)
        ->and(Trip::destroy($trip->id))->toBe(1);
    $this->assertSoftDeleted($trip);
    $archived = Trip::withTrashed()->findOrFail($trip->id);
    expect(fn () => $archived->forceDeleteQuietly())->toThrow(ValidationException::class)
        ->and($archived->restore())->toBeTrue()
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['in_progress', 'completed', 'cancelled']);
