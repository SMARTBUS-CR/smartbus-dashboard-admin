<?php

use App\Models\Route;
use App\Models\Stop;

test('filters stops by an inclusive geographic bounding box', function (bool $asArray): void {
    $inside = Stop::factory()->shared()->create(['latitude' => 9.93, 'longitude' => -84.08]);
    $boundary = Stop::factory()->shared()->create(['latitude' => 9.90, 'longitude' => -84.10]);
    Stop::factory()->shared()->create(['latitude' => 10.20, 'longitude' => -84.08]);
    $bbox = [-84.10, 9.90, -84.00, 10.00];

    $response = $this->getJson('/api/v1/operations/stops?'.http_build_query([
        'filter' => ['bbox' => $asArray ? $bbox : implode(',', $bbox)],
    ]))->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$inside->id, $boundary->id]);
})->with([false, true]);

test('filters stops by distance instead of a rectangular approximation', function (bool $asArray): void {
    $center = Stop::factory()->shared()->create(['latitude' => 9.93, 'longitude' => -84.08]);
    $near = Stop::factory()->shared()->create(['latitude' => 9.934, 'longitude' => -84.08]);
    Stop::factory()->shared()->create(['latitude' => 9.938, 'longitude' => -84.072]);
    Stop::factory()->shared()->create(['latitude' => 9.95, 'longitude' => -84.08]);
    $filter = [9.93, -84.08, 1000];

    $response = $this->getJson('/api/v1/operations/stops?'.http_build_query([
        'filter' => ['near' => $asArray ? $filter : implode(',', $filter)],
    ]))->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$center->id, $near->id]);
})->with([false, true]);

test('rejects malformed or excessive geographic filters with 422', function (string $filter, string $value, string $error): void {
    $this->getJson('/api/v1/operations/stops?'.http_build_query(['filter' => [$filter => $value]]))
        ->assertUnprocessable()->assertJsonValidationErrors([$error]);
})->with([
    'incomplete bbox' => ['bbox', '-84,9,-83', 'filter.bbox'],
    'invalid longitude' => ['bbox', '-181,9,-84,10', 'filter.bbox.0'],
    'invalid latitude' => ['bbox', '-84,91,-83,92', 'filter.bbox.1'],
    'inverted longitude' => ['bbox', '-83,9,-84,10', 'filter.bbox'],
    'inverted latitude' => ['bbox', '-84,10,-83,9', 'filter.bbox'],
    'excessive bbox' => ['bbox', '-84,9,-82,10', 'filter.bbox'],
    'incomplete radius' => ['near', '9,-84', 'filter.near'],
    'non numeric radius' => ['near', '9,-84,no', 'filter.near.2'],
    'invalid center latitude' => ['near', '91,-84,500', 'filter.near.0'],
    'invalid center longitude' => ['near', '9,181,500', 'filter.near.1'],
    'zero radius' => ['near', '9,-84,0', 'filter.near.2'],
    'excessive radius' => ['near', '9,-84,2001', 'filter.near.2'],
]);

test('stop pagination preserves geographic filters and consistently orders equal names by UUID', function (): void {
    $ids = [
        '00000000-0000-4000-8000-000000000003',
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
    ];
    foreach ($ids as $id) {
        Stop::factory()->shared()->create(['id' => $id, 'name' => 'Central', 'latitude' => 9.93, 'longitude' => -84.08]);
    }
    Stop::factory()->shared()->create(['name' => 'Other', 'latitude' => 9.93, 'longitude' => -84.08]);
    Stop::factory()->shared()->create(['name' => 'Central', 'latitude' => 10.50, 'longitude' => -84.08]);
    $uri = '/api/v1/operations/stops?'.http_build_query([
        'filter' => ['name' => 'Central', 'bbox' => '-84.1,9.9,-84,10'],
        'sort' => '-name', 'page' => ['size' => 1],
    ]);
    $seen = [];

    for ($page = 1; $page <= 3; $page++) {
        $response = $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $seen[] = $response->json('data.0.id');
        $uri = $response->json('links.next');
    }

    sort($ids);
    expect($seen)->toBe($ids)
        ->and($uri)->toBeNull();
});

test('route pagination preserves filters size and sort with equal route codes', function (): void {
    $ids = [
        '00000000-0000-4000-8000-000000000003',
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
    ];
    foreach ($ids as $id) {
        Route::factory()->create(['id' => $id, 'name' => 'Central', 'code' => 'DUPLICATE']);
    }
    Route::factory()->create(['name' => 'Other', 'code' => 'DUPLICATE']);
    $uri = '/api/v1/operations/routes?'.http_build_query([
        'filter' => ['name' => 'Central'], 'sort' => '-code', 'page' => ['size' => 1],
    ]);
    $seen = [];

    for ($page = 1; $page <= 3; $page++) {
        $response = $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $seen[] = $response->json('data.0.id');
        $uri = $response->json('links.next');
    }

    sort($ids);
    expect($seen)->toBe($ids)
        ->and($uri)->toBeNull();
});

test('rejects empty page sizes instead of using a zero size', function (string $resource): void {
    $this->getJson('/api/v1/operations/'.$resource.'?page[size]=')->assertUnprocessable()
        ->assertJsonValidationErrors(['page.size']);
})->with(['routes', 'stops']);
