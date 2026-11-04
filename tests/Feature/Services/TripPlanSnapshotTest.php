<?php

use App\Models\Bus;
use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Services\TripPlanSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{Company, RoutePattern, array<string, mixed>} */
function snapshotPlanningInputs(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $route = Route::factory()->for($company)->create(['code' => 'R-37', 'name' => 'Ruta / Río']);
    $pattern = RoutePattern::factory()->for($route)->withSavedPlan()->create([
        'code' => 'P-37', 'name' => 'Circuito norte', 'headsign' => 'Centro',
    ]);
    $occurrences = $pattern->stopOccurrences()->with('stop')->get();
    $occurrences[0]->stop->update(['name' => 'Terminal / Río']);
    $occurrences[1]->stop->update(['name' => 'Centro']);
    $occurrences[1]->update(['minutes_from_start' => 15]);
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '06:30:00']);
    $bus = Bus::factory()->for($company)->active()->create();

    return [$company, $pattern, [
        'route_pattern_id' => $pattern->getKey(), 'route_schedule_id' => $schedule->getKey(),
        'bus_id' => $bus->getKey(), 'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00',
    ]];
}

test('builds the exact historical projection and revision from persisted planning data', function (): void {
    [$company, $pattern, $data] = snapshotPlanningInputs();
    $occurrences = $pattern->stopOccurrences()->with('stop')->get();

    $plan = app(TripPlanSnapshotService::class)->prepare($company, $data);
    $snapshot = $plan['planned_route_snapshot'];
    $revision = $snapshot['revision_hash'];
    unset($snapshot['revision_hash']);

    $expected = [
        'company_timezone' => 'America/Costa_Rica',
        'distance_meters' => '1500.00', 'driving_duration_seconds' => '300.00', 'headsign' => 'Centro',
        'occurrences' => [
            ['lat' => '10.0000000', 'lng' => '-84.0000000', 'minutes_from_start' => null, 'name' => 'Terminal / Río',
                'occurrence_id' => $occurrences[0]->getKey(), 'stop_id' => $occurrences[0]->stop_id, 'stop_sequence' => 1],
            ['lat' => '10.0100000', 'lng' => '-84.0100000', 'minutes_from_start' => 15, 'name' => 'Centro',
                'occurrence_id' => $occurrences[1]->getKey(), 'stop_id' => $occurrences[1]->stop_id, 'stop_sequence' => 2],
        ],
        'pattern_code' => 'P-37', 'pattern_id' => $pattern->getKey(), 'pattern_name' => 'Circuito norte',
        'route_code' => 'R-37',
        'route_geometry' => ['coordinates' => [[-84, 10], [-84.01, 10.01]], 'type' => 'LineString'],
        'route_id' => $pattern->route_id, 'route_name' => 'Ruta / Río',
        'routing_leg_distances' => [1500], 'routing_points_hash' => $pattern->fresh()->routing_points_hash, 'schema_version' => 1,
    ];
    expect($snapshot)->toBe($expected);
    expect($revision)->toBe(hash('sha256', json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)));
    expect($plan['departure_at']->format('H:i:sP'))->toBe('12:30:00+00:00');
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});

test('canonical revision ignores object key order and its own value but preserves list order', function (): void {
    $service = app(TripPlanSnapshotService::class);
    $original = ['b' => ['z' => 2, 'a' => 1], 'a' => [['id' => 'A'], ['id' => 'B']]];
    $reordered = ['revision_hash' => 'forged', 'a' => [['id' => 'A'], ['id' => 'B']], 'b' => ['a' => 1, 'z' => 2]];

    $revision = $service->revisionHash($original);

    expect($revision)->toBe(hash('sha256', '{"a":[{"id":"A"},{"id":"B"}],"b":{"a":1,"z":2}}'))
        ->and($service->revisionHash($reordered))->toBe($revision);
    $reordered['a'] = array_reverse($reordered['a']);
    expect($service->revisionHash($reordered))->not->toBe($revision);
});

test('retains the revision through a real PostgreSQL JSONB round trip', function (): void {
    [$company, , $data] = snapshotPlanningInputs();
    $service = app(TripPlanSnapshotService::class);
    $snapshot = $service->prepare($company, $data)['planned_route_snapshot'];

    $stored = DB::selectOne('SELECT CAST(? AS jsonb) AS snapshot', [json_encode($snapshot, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
    $restored = json_decode($stored->snapshot, true, 512, JSON_THROW_ON_ERROR);

    expect($service->revisionHash($restored))->toBe($snapshot['revision_hash']);
});

test('catalogue edits change the full revision without mutating previous snapshots', function (string $field): void {
    [$company, $pattern, $data] = snapshotPlanningInputs();
    $service = app(TripPlanSnapshotService::class);
    $original = $service->prepare($company, $data)['planned_route_snapshot'];
    $before = json_encode($original, JSON_THROW_ON_ERROR);
    match ($field) {
        'pattern name' => $pattern->update(['name' => 'Nuevo recorrido']),
        'route name' => $pattern->route->update(['name' => 'Nueva ruta']),
        'stop name' => $pattern->stopOccurrences()->first()->stop->update(['name' => 'Nuevo nombre']),
        'timing estimate' => $pattern->stopOccurrences()->reorder('stop_sequence', 'desc')->first()->update(['minutes_from_start' => 20]),
        'metrics' => $pattern->update(['distance_meters' => '1600.00']),
    };

    $updated = $service->prepare($company, $data)['planned_route_snapshot'];

    expect($updated['routing_points_hash'])->toBe($original['routing_points_hash'])
        ->and($updated['revision_hash'])->not->toBe($original['revision_hash']);
    expect(json_encode($original, JSON_THROW_ON_ERROR))->toBe($before);
    $this->assertDatabaseCount('trips', 0);
})->with(['pattern name', 'route name', 'stop name', 'timing estimate', 'metrics']);

test('keeps repeated stops distinct and preserves nullable historical values', function (): void {
    [$company, $pattern, $data] = snapshotPlanningInputs();
    $first = $pattern->stopOccurrences()->firstOrFail();
    RoutePatternStop::factory()->for($pattern, 'pattern')->create(['stop_id' => $first->stop_id, 'stop_sequence' => 3]);
    $pattern->refresh()->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01], [-84, 10]]],
        'distance_meters' => '3000.00', 'driving_duration_seconds' => '600.00',
        'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0100000,10.0100000;-84.0000000,10.0000000'),
        'routing_leg_distances' => null,
    ]);

    $snapshot = app(TripPlanSnapshotService::class)->prepare($company, $data)['planned_route_snapshot'];

    expect($snapshot['headsign'])->toBe('Centro')->and($snapshot['routing_leg_distances'])->toBeNull();
    expect(array_column($snapshot['occurrences'], 'stop_sequence'))->toBe([1, 2, 3]);
    expect(array_column($snapshot['occurrences'], 'minutes_from_start'))->toBe([null, 15, null]);
    expect(array_column($snapshot['occurrences'], 'occurrence_id'))->toHaveCount(3);
    expect($snapshot['occurrences'][0]['stop_id'])->toBe($snapshot['occurrences'][2]['stop_id']);
    expect($snapshot['occurrences'][0]['occurrence_id'])->not->toBe($snapshot['occurrences'][2]['occurrence_id']);
});

test('rejects forged snapshots geometry and revisions before constructing history', function (string $field): void {
    [$company, , $data] = snapshotPlanningInputs();
    $data[$field] = ['forged' => true];

    try {
        app(TripPlanSnapshotService::class)->prepare($company, $data);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        $this->assertDatabaseCount('trips', 0);
    }
})->with(['planned_route_snapshot', 'route_geometry', 'revision_hash']);
