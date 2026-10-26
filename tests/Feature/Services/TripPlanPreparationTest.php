<?php

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use App\Services\TripPlanPreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{Company, RoutePattern, RouteSchedule, Bus, array<string, mixed>} */
function tripPlanningInputs(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $pattern = RoutePattern::factory()->for(Route::factory()->for($company)->create())->withSavedPlan()->create();
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '06:30:00']);
    $bus = Bus::factory()->for($company)->active()->create();

    return [$company, $pattern, $schedule, $bus, [
        'route_pattern_id' => $pattern->getKey(), 'route_schedule_id' => $schedule->getKey(),
        'bus_id' => $bus->getKey(), 'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00',
    ]];
}

test('prepares saved catalogue and UTC times without writes or external services', function (): void {
    [$company, $pattern, $schedule, $bus, $data] = tripPlanningInputs();

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['pattern']->is($pattern))->toBeTrue()
        ->and($plan['schedule']->is($schedule))->toBeTrue()->and($plan['bus']->is($bus))->toBeTrue()
        ->and($plan['service_date'])->toBe('2026-10-26')
        ->and($plan['departure_at']->format('Y-m-d H:i:sP'))->toBe('2026-10-26 12:30:00+00:00')
        ->and($plan['arrival_at']->format('Y-m-d H:i:sP'))->toBe('2026-10-26 13:30:00+00:00')
        ->and($plan['local_dates'])->toBe(['2026-10-26'])->and($plan['occurrences']->count())->toBe(2);
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});

test('rejects unavailable companies resources and calendar dates', function (string $scenario, string $field): void {
    [$company, $pattern, $schedule, $bus, $data] = tripPlanningInputs();
    match ($scenario) {
        'inactive company' => Company::query()->whereKey($company)->update(['status' => CompanyStatus::INACTIVE->value]),
        'archived company' => $company->delete(),
        'archived route' => $pattern->route->delete(), 'archived pattern' => $pattern->delete(),
        'inactive bus' => Bus::query()->whereKey($bus)->update(['is_active' => false]), 'archived bus' => $bus->delete(),
        'foreign pattern' => $data['route_pattern_id'] = RoutePattern::factory()->withSavedPlan()->create()->getKey(),
        'foreign schedule' => $data['route_schedule_id'] = RouteSchedule::factory()->create()->getKey(),
        'foreign bus' => $data['bus_id'] = Bus::factory()->create()->getKey(),
        'weekday' => $schedule->update(['day_of_week' => 2]),
        'valid from' => $schedule->update(['valid_from' => '2026-11-01']),
        'valid until' => $schedule->update(['valid_until' => '2026-10-25']),
        'suspension' => RouteScheduleException::factory()->create(['route_schedule_id' => $schedule->getKey(), 'service_date' => '2026-10-26']),
    };

    try {
        app(TripPlanPreparationService::class)->prepare($company, $data);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        $this->assertDatabaseCount('trips', 0);
    }
})->with([
    ['inactive company', 'company'], ['archived company', 'company'], ['archived route', 'route_pattern_id'],
    ['archived pattern', 'route_pattern_id'], ['inactive bus', 'bus_id'], ['archived bus', 'bus_id'],
    ['foreign pattern', 'route_pattern_id'], ['foreign schedule', 'route_schedule_id'], ['foreign bus', 'bus_id'],
    ['weekday', 'service_date'], ['valid from', 'service_date'], ['valid until', 'service_date'], ['suspension', 'service_date'],
]);

test('rejects incomplete stale or unavailable saved route plans', function (string $scenario): void {
    [$company, $pattern, , , $data] = tripPlanningInputs();
    $occurrence = $pattern->stopOccurrences()->with('stop')->firstOrFail();
    match ($scenario) {
        'missing geometry' => $pattern->update(['route_geometry' => null, 'distance_meters' => null, 'driving_duration_seconds' => null, 'routing_points_hash' => null]),
        'invalid coordinates' => DB::table('route_patterns')->where('id', $pattern->getKey())->update([
            'route_geometry' => json_encode(['type' => 'LineString', 'coordinates' => [[200, 10], [-84.01, 10.01]]], JSON_THROW_ON_ERROR),
        ]),
        'wrong leg count' => $pattern->update(['routing_leg_distances' => [750, 750]]),
        'stale hash' => $pattern->update(['routing_points_hash' => str_repeat('a', 64)]),
        'too few occurrences' => DB::table('route_pattern_stops')->where('id', $occurrence->getKey())->delete(),
        'archived stop' => DB::table('stops')->where('id', $occurrence->stop_id)->update(['deleted_at' => now()]),
        'changed coordinates' => DB::table('stops')->where('id', $occurrence->stop_id)->update(['latitude' => 11]),
    };

    try {
        app(TripPlanPreparationService::class)->prepare($company, $data);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('route_pattern_id');
    }
})->with(['missing geometry', 'invalid coordinates', 'wrong leg count', 'stale hash', 'too few occurrences', 'archived stop', 'changed coordinates']);

test('accepts saved detours and nullable leg distances without recalculation', function (bool $nullableLegs): void {
    [$company, $pattern, , , $data] = tripPlanningInputs();
    $pattern->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-85, 11], [-84.01, 10.01]]],
        'distance_meters' => '250000.00', 'driving_duration_seconds' => '12000.00',
        'routing_leg_distances' => $nullableLegs ? null : [250000],
    ]);
    $before = $pattern->fresh()->getRawOriginal();

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['pattern']->route_geometry['coordinates'])->toHaveCount(3);
    expect($pattern->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with([false, true]);

test('rejects forged fields and invalid local dates or arrivals', function (string $field, mixed $value): void {
    [$company, , , , $data] = tripPlanningInputs();
    $data[$field] = $value;

    try {
        app(TripPlanPreparationService::class)->prepare($company, $data);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    ['planned_route_snapshot', []], ['route_geometry', []], ['status', 'scheduled'],
    ['service_date', '2026-02-30'], ['service_date', '26/10/2026'],
    ['arrival_at', '2026-10-26 07:30:00'], ['arrival_at', '2026-10-26T07:30:00-05:00'],
    ['arrival_at', '2026-02-30T07:30:00-06:00'], ['arrival_at', '2026-10-26T07:30:60-06:00'],
    ['arrival_at', '2026-10-26T06:30:00-06:00'], ['arrival_at', '2026-10-26T06:00:00-06:00'],
    ['departure_utc_offset', '-05:00'],
]);

test('counts only local dates touched by the half open interval', function (string $arrival, array $dates): void {
    [$company, , $schedule, , $data] = tripPlanningInputs();
    $schedule->update(['departure_time' => '23:00:00']);
    $data['arrival_at'] = $arrival;

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['local_dates'])->toBe($dates)
        ->and($plan['departure_at']->format('Y-m-d H:i:sP'))->toBe('2026-10-27 05:00:00+00:00');
})->with([
    ['2026-10-27T00:00:00-06:00', ['2026-10-26']],
    ['2026-10-27T01:00:00-06:00', ['2026-10-26', '2026-10-27']],
]);

test('preserves repeated occurrences and null timing estimates in prepared data', function (): void {
    [$company, $pattern, , , $data] = tripPlanningInputs();
    $original = $pattern->stopOccurrences()->get();
    RoutePatternStop::factory()->for($pattern, 'pattern')->create([
        'stop_id' => $original->first()->stop_id, 'stop_sequence' => 3, 'minutes_from_start' => null,
    ]);
    $pattern->refresh()->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01], [-84, 10]]],
        'distance_meters' => '3000.00', 'driving_duration_seconds' => '600.00',
        'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0100000,10.0100000;-84.0000000,10.0000000'),
        'routing_leg_distances' => [1500, 1500],
    ]);

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['occurrences']->pluck('stop_id')->all())->toBe([$original[0]->stop_id, $original[1]->stop_id, $original[0]->stop_id]);
    expect($plan['occurrences']->pluck('id')->unique())->toHaveCount(3);
    expect($plan['occurrences']->pluck('minutes_from_start')->all())->toBe([null, null, null]);
});

test('includes schedule validity endpoints and preserves explicit fractional arrival instants', function (): void {
    [$company, , $schedule, , $data] = tripPlanningInputs();
    $schedule->update(['valid_from' => '2026-10-26', 'valid_until' => '2026-10-26']);
    $data['arrival_at'] = '2026-10-26T07:30:00.123456-06:00';

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['arrival_at']->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 13:30:00.123456+00:00');
});

test('validates the saved points hash and legs including routing adjustments', function (): void {
    [$company, $pattern, , , $data] = tripPlanningInputs();
    $occurrences = $pattern->stopOccurrences()->get();
    $pattern->update(['routing_adjustments' => [[
        'from_occurrence_id' => $occurrences[0]->getKey(), 'to_occurrence_id' => $occurrences[1]->getKey(),
        'points' => [['lat' => 10.005, 'lng' => -84.005]],
    ]]]);
    $pattern->refresh()->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.005, 10.005], [-84.01, 10.01]]],
        'distance_meters' => '1500.00', 'driving_duration_seconds' => '300.00',
        'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0050000,10.0050000;-84.0100000,10.0100000'),
        'routing_leg_distances' => [750, 750],
    ]);

    $plan = app(TripPlanPreparationService::class)->prepare($company, $data);

    expect($plan['occurrences'])->toHaveCount(2);
    expect($plan['pattern']->routing_leg_distances)->toBe([750, 750]);
    Http::assertNothingSent();
});

test('resolves DST departures only with valid explicit offsets', function (string $date, string $time, ?string $offset, ?string $utc, string $field): void {
    [$company, , $schedule, , $data] = tripPlanningInputs();
    $company->update(['timezone' => 'America/New_York']);
    $schedule->update(['day_of_week' => 0, 'departure_time' => $time]);
    $data['service_date'] = $date;
    $data['arrival_at'] = $date === '2026-03-08' ? '2026-03-08T03:30:00-04:00' : '2026-11-01T02:30:00-05:00';
    if ($offset !== null) {
        $data['departure_utc_offset'] = $offset;
    }

    if ($utc !== null) {
        $plan = app(TripPlanPreparationService::class)->prepare($company, $data);
        expect($plan['departure_at']->format('Y-m-d H:i:sP'))->toBe($utc);
    } else {
        try {
            app(TripPlanPreparationService::class)->prepare($company, $data);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }
    }
})->with([
    ['2026-03-08', '02:30:00', null, null, 'service_date'],
    ['2026-11-01', '01:30:00', null, null, 'departure_utc_offset'],
    ['2026-11-01', '01:30:00', '-06:00', null, 'departure_utc_offset'],
    ['2026-11-01', '01:30:00', '-04:00', '2026-11-01 05:30:00+00:00', ''],
    ['2026-11-01', '01:30:00', '-05:00', '2026-11-01 06:30:00+00:00', ''],
]);

test('returns localized preparation errors without partial writes', function (string $problem, string $locale, string $arrivalMessage, string $planMessage): void {
    [$company, $pattern, , , $data] = tripPlanningInputs();
    app()->setLocale($locale);
    if ($problem === 'arrival') {
        $data['arrival_at'] = '2026-10-26T06:30:00-06:00';
    } else {
        $pattern->update(['routing_points_hash' => str_repeat('a', 64)]);
    }
    $before = $pattern->fresh()->getRawOriginal();

    try {
        app(TripPlanPreparationService::class)->prepare($company, $data);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe($problem === 'arrival' ? ['arrival_at' => [$arrivalMessage]] : ['route_pattern_id' => [$planMessage]]);
        expect($pattern->fresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('trips', 0);
    }
})->with(['arrival', 'plan'])->with([
    ['en', 'Arrival must be explicitly later than departure.', 'The saved route plan is incomplete or outdated. Calculate and save the route before planning.'],
    ['es', 'La llegada debe ser explícitamente posterior a la salida.', 'El trazado guardado está incompleto o desactualizado. Calcula y guarda la ruta antes de planificar.'],
]);
