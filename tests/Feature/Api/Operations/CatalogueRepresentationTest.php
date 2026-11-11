<?php

use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Illuminate\Support\Facades\DB;

test('pattern revisions are stable across endpoints and change when published stop estimates change', function (): void {
    $pattern = RoutePattern::factory()->withSavedPlan()->create();
    $uri = '/api/v1/operations/route-patterns/'.$pattern->id;

    $response = $this->getJson($uri)->assertOk()->assertJsonStructure(['data' => ['attributes' => ['revision_hash']]]);
    $revision = $response->json('data.attributes.revision_hash');
    expect($revision)->toMatch('/\A[a-f0-9]{64}\z/');
    $this->getJson($uri)->assertOk()->assertJsonPath('data.attributes.revision_hash', $revision);
    $list = $this->getJson('/api/v1/operations/routes/'.$pattern->route_id.'/patterns')->assertOk();
    expect($list->json('data.0.attributes.revision_hash'))->toBe($revision);
    foreach ([
        '/api/v1/operations/routes/'.$pattern->route_id,
        '/api/v1/operations/routes?include=patterns',
    ] as $routeUri) {
        $included = collect($this->getJson($routeUri)->assertOk()->json('included'))
            ->firstWhere('id', $pattern->id);
        expect(data_get($included, 'attributes.revision_hash'))->toBe($revision);
    }

    $pattern->stopOccurrences()->orderByDesc('stop_sequence')->firstOrFail()->update(['minutes_from_start' => 7]);

    $changed = $this->getJson($uri)->assertOk()->json('data.attributes.revision_hash');
    expect($changed)->toMatch('/\A[a-f0-9]{64}\z/')->not->toBe($revision);
    $this->getJson($uri)->assertOk()->assertJsonPath('data.attributes.revision_hash', $changed);
});

test('schedule representations expose ordered suspension dates without per-schedule queries', function (): void {
    $pattern = RoutePattern::factory()->create();
    $first = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '07:00:00']);
    $second = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '08:00:00']);
    foreach (['2026-11-16', '2026-11-09'] as $date) {
        RouteScheduleException::factory()->for($first, 'schedule')->create(['service_date' => $date]);
    }
    $uri = '/api/v1/operations/route-patterns/'.$pattern->id.'/schedules';
    $connection = DB::connection('pgsql');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $response = $this->getJson($uri)->assertOk();
    $smallQueryCount = count($connection->getQueryLog());
    $response->assertJsonPath('data.0.attributes.suspended_dates', ['2026-11-09', '2026-11-16'])
        ->assertJsonPath('data.1.attributes.suspended_dates', []);

    foreach (range(9, 16) as $hour) {
        RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => sprintf('%02d:00:00', $hour)]);
    }
    $connection->flushQueryLog();
    $this->getJson($uri)->assertOk()->assertJsonCount(10, 'data');
    expect(count($connection->getQueryLog()))->toBe($smallQueryCount);
    $connection->disableQueryLog();

    $dated = $this->getJson($uri.'?date=2026-11-09')->assertOk();
    $ids = collect($dated->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($first->id)->toContain($second->id);
});
