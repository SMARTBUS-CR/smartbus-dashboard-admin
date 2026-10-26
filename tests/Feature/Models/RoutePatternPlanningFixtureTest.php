<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Services\RouteRoutingPointsService;
use Illuminate\Support\Facades\Http;

test('provides a saved planning catalogue with real ordered occurrences and no network', function (): void {
    Http::preventStrayRequests();
    $company = createCompany();
    $route = Route::factory()->for($company)->create();
    $pattern = RoutePattern::factory()->for($route)->withSavedPlan()->create()->refresh();
    $occurrences = $pattern->stopOccurrences()->with('stop')->get();
    $points = app(RouteRoutingPointsService::class)->build($occurrences->map(fn ($occurrence): array => [
        'occurrence_id' => $occurrence->getKey(), 'lat' => $occurrence->stop->latitude,
        'lng' => $occurrence->stop->longitude,
    ])->all(), []);
    $coordinates = implode(';', array_map(fn (array $point): string => sprintf('%.7F,%.7F', $point['lng'], $point['lat']), $points));

    expect($occurrences)->toHaveCount(2);
    expect($occurrences->pluck('stop_sequence')->all())->toBe([1, 2]);
    expect($occurrences->pluck('stop.company_id')->all())->toBe([$company->getKey(), $company->getKey()]);
    expect($occurrences->pluck('minutes_from_start')->all())->toBe([null, null]);
    expect($coordinates)->toBe('-84.0000000,10.0000000;-84.0100000,10.0100000');
    expect($pattern->route_geometry)->toBe([
        'type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01]],
    ])->and($pattern->distance_meters)->toBe('1500.00')
        ->and($pattern->driving_duration_seconds)->toBe('300.00')
        ->and($pattern->routing_points_hash)->toBe(hash('sha256', $coordinates))
        ->and($pattern->routing_leg_distances)->toBe([1500]);
    Http::assertNothingSent();
});
