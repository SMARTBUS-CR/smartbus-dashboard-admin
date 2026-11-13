<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;

describe('Public Trip Reads', function (): void {
    it('lists actual ongoing trips for guests passengers and drivers without assignment scope', function (string $actor): void {
        Http::preventStrayRequests();
        $trip = Trip::factory()->inProgress()->create();
        $other = Trip::factory()->inProgress()->create();
        if ($actor !== 'guest') {
            $this->actingAs(createUserWithRole($actor === 'passenger' ? UserRole::Passenger : UserRole::Driver, createCompany()));
        }

        $this->getJson("/api/v1/operations/route-patterns/{$trip->route_pattern_id}/trips")
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'trips')
            ->assertJsonPath('data.0.id', $trip->id)
            ->assertJsonPath('data.0.attributes.status', 'in_progress')
            ->assertJsonMissing(['id' => $other->id]);
        Http::assertNothingSent();
    })->with(['guest', 'passenger', 'driver']);

    it('returns only public trip fields and the historical headsign even when includes are requested', function (string $operation): void {
        Http::preventStrayRequests();
        $trip = Trip::factory()->inProgress()->create();
        $snapshot = $trip->planned_route_snapshot;
        $before = $trip->getRawOriginal();
        $trip->pattern->update(['headsign' => 'Changed catalogue destination']);
        $this->actingAs(createUserWithRole(UserRole::Passenger));

        $path = $operation === 'list'
            ? "route-patterns/{$trip->route_pattern_id}/trips"
            : "public/trips/{$trip->id}";
        $response = $this->getJson("/api/v1/operations/{$path}?include=driver,company,pattern,bus");
        $data = $operation === 'list' ? 'data.0' : 'data';

        $response->assertOk()->assertJsonPath($data.'.type', 'trips')->assertJsonPath($data.'.id', $trip->id);
        expect($response->json($data.'.attributes'))->toBe([
            'route_pattern_id' => $trip->route_pattern_id,
            'status' => 'in_progress',
            'scheduled_departure_at' => '2026-10-26T12:30:00.000000Z',
            'scheduled_arrival_at' => '2026-10-26T13:30:00.000000Z',
            'actual_departure_at' => '2026-10-26T12:30:00.000000Z',
            'actual_arrival_at' => null,
            'headsign' => $snapshot['headsign'],
            'bus' => ['id' => $trip->bus_id, 'plate_number' => $trip->bus->plate_number, 'unit_number' => $trip->bus->unit_number],
        ])
            ->and($response->json('included'))->toBeNull()
            ->and($response->json($data.'.relationships'))->toBeEmpty()
            ->and($response->getContent())->not->toContain('driver_id', 'user_id', 'salary_', 'license', 'password', 'remember_token', 'cancellation_reason', 'planned_route_snapshot')
            ->and($trip->fresh()->getRawOriginal())->toBe($before);
        Http::assertNothingSent();
    })->with(['list', 'detail']);

    it('paginates ongoing trips with stable ordering and preserved page parameters', function (): void {
        $first = Trip::factory()->inProgress()->create(['id' => '00000000-0000-4000-8000-000000000001']);
        $second = Trip::factory()->for($first->company)->inProgress()->create([
            'id' => '00000000-0000-4000-8000-000000000002', 'route_pattern_id' => $first->route_pattern_id,
            'route_schedule_id' => RouteSchedule::factory()->for($first->pattern, 'pattern')->create([
                'day_of_week' => 1, 'departure_time' => '07:30:00',
            ])->id,
        ]);

        $url = "/api/v1/operations/route-patterns/{$first->route_pattern_id}/trips?".http_build_query(['page' => ['size' => 1, 'number' => 1]]);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.total', 2);
        $next = $response->json('links.next');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        expect($query['page'])->toBe(['size' => '1', 'number' => '2']);
        $this->getJson($next)->assertOk()->assertJsonPath('data.0.id', $second->id);
    });

    it('validates public pagination bounds', function (array $page, string $field): void {
        $trip = Trip::factory()->inProgress()->create();
        $this->getJson("/api/v1/operations/route-patterns/{$trip->route_pattern_id}/trips?".http_build_query(['page' => $page]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        [['size' => 0], 'page.size'], [['size' => 101], 'page.size'], [['number' => 0], 'page.number'],
    ]);

    it('omits unpublished trip states and archived trips from lists and public details', function (string $state): void {
        $factory = Trip::factory();
        $trip = match ($state) {
            'completed' => $factory->completed()->create(),
            'cancelled' => $factory->cancelled()->create(),
            'archived' => $factory->inProgress()->create(),
            default => $factory->create(),
        };
        if ($state === 'archived') {
            $trip->delete();
        }

        $this->getJson("/api/v1/operations/route-patterns/{$trip->route_pattern_id}/trips")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/operations/public/trips/{$trip->id}")->assertNotFound();
    })->with(['scheduled', 'completed', 'cancelled', 'archived']);

    it('hides ongoing trips outside the published catalogue', function (string $hidden): void {
        $trip = Trip::factory()->inProgress()->create();
        match ($hidden) {
            'inactive company' => $trip->company->update(['status' => CompanyStatus::INACTIVE]),
            'archived company' => $trip->company->delete(),
            'archived route' => $trip->pattern->route->delete(),
            'archived pattern' => $trip->pattern->delete(),
        };

        $this->getJson("/api/v1/operations/route-patterns/{$trip->route_pattern_id}/trips")->assertNotFound();
        $this->getJson("/api/v1/operations/public/trips/{$trip->id}")->assertNotFound();
    })->with(['inactive company', 'archived company', 'archived route', 'archived pattern']);

    it('returns 404 for missing or malformed public identifiers', function (string $id): void {
        $this->getJson("/api/v1/operations/route-patterns/{$id}/trips")->assertNotFound();
        $this->getJson("/api/v1/operations/public/trips/{$id}")->assertNotFound();
    })->with(['00000000-0000-4000-8000-000000000099', 'invalid']);

    it('keeps private detail and lifecycle mutations forbidden to passengers', function (): void {
        $trip = Trip::factory()->inProgress()->create();
        $before = $trip->getRawOriginal();
        $this->actingAs(createUserWithRole(UserRole::Passenger));

        $this->getJson("/api/v1/operations/public/trips/{$trip->id}")->assertOk();
        $this->getJson("/api/v1/operations/trips/{$trip->id}")->assertForbidden();
        $this->postJson("/api/v1/operations/trips/{$trip->id}/start")->assertForbidden();
        $this->postJson("/api/v1/operations/trips/{$trip->id}/complete")->assertForbidden();
        expect($trip->fresh()->getRawOriginal())->toBe($before);
    });
});
