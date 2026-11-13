<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Database\Seeders\SmartbusDemoRouteSeeder;
use Illuminate\Support\Facades\Http;

describe('Smartbus Demo Route Seeding', function (): void {
    test('creates one complete offline Monday plan for the demo company', function (): void {
        Http::preventStrayRequests();
        $company = createCompany(['slug' => 'smartbus-demo', 'timezone' => 'America/Costa_Rica']);

        $this->seed(SmartbusDemoRouteSeeder::class);

        $route = Route::query()->where('company_id', $company->id)->where('code', 'DEMO-CITY')->sole();
        $pattern = RoutePattern::query()->where('route_id', $route->id)->where('code', 'OUTBOUND')->sole();
        $occurrences = RoutePatternStop::query()->where('route_pattern_id', $pattern->id)
            ->with('stop')->orderBy('stop_sequence')->get();
        $schedule = RouteSchedule::query()->where('route_pattern_id', $pattern->id)->sole();

        expect($company->stops()->count())->toBe(2)
            ->and($occurrences)->toHaveCount(2)
            ->and($occurrences->pluck('stop_sequence')->all())->toBe([1, 2])
            ->and($occurrences->pluck('minutes_from_start')->all())->toBe([0, 15])
            ->and($pattern->route_geometry)->toBe([
                'type' => 'LineString',
                'coordinates' => [[-84.0907, 9.9281], [-84.0786, 9.9355]],
            ])
            ->and($pattern->distance_meters)->toBe('1800.00')
            ->and($pattern->driving_duration_seconds)->toBe('900.00')
            ->and($pattern->routing_points_hash)->toMatch('/^[a-f0-9]{64}$/')
            ->and($pattern->routing_leg_distances)->toBe([1800])
            ->and($schedule->day_of_week)->toBe(1)
            ->and($schedule->departure_time)->toBe('09:00:00')
            ->and($schedule->valid_from)->toBeNull()
            ->and($schedule->valid_until)->toBeNull();
        Http::assertNothingSent();
    });

    test('is repeatable and preserves existing demo planning records', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->seed(SmartbusDemoRouteSeeder::class);
        $route = Route::query()->where('company_id', $company->id)->where('code', 'DEMO-CITY')->sole();
        $pattern = RoutePattern::query()->where('route_id', $route->id)->sole();
        $before = [
            'routes' => Route::query()->where('company_id', $company->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'patterns' => RoutePattern::query()->where('route_id', $route->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'stops' => Stop::query()->where('company_id', $company->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'occurrences' => RoutePatternStop::query()->where('route_pattern_id', $pattern->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'schedules' => RouteSchedule::query()->where('route_pattern_id', $pattern->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];

        $this->seed(SmartbusDemoRouteSeeder::class);

        expect(Route::query()->where('company_id', $company->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before['routes'])
            ->and(RoutePattern::query()->where('route_id', $route->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before['patterns'])
            ->and(Stop::query()->where('company_id', $company->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before['stops'])
            ->and(RoutePatternStop::query()->where('route_pattern_id', $pattern->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before['occurrences'])
            ->and(RouteSchedule::query()->where('route_pattern_id', $pattern->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before['schedules']);
    });

    test('repairs missing generated planning dependencies without replacing their owners', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->seed(SmartbusDemoRouteSeeder::class);
        $route = Route::query()->where('company_id', $company->id)->where('code', 'DEMO-CITY')->sole();
        $pattern = RoutePattern::query()->where('route_id', $route->id)->sole();
        RoutePatternStop::query()->where('route_pattern_id', $pattern->id)->where('stop_sequence', 2)->sole()->delete();
        RouteSchedule::query()->where('route_pattern_id', $pattern->id)->delete();

        $this->seed(SmartbusDemoRouteSeeder::class);

        expect(Route::query()->whereKey($route->id)->exists())->toBeTrue()
            ->and(RoutePattern::query()->whereKey($pattern->id)->exists())->toBeTrue()
            ->and(RoutePatternStop::query()->where('route_pattern_id', $pattern->id)->count())->toBe(2)
            ->and(RoutePattern::query()->findOrFail($pattern->id)->routing_points_hash)->toMatch('/^[a-f0-9]{64}$/')
            ->and(RouteSchedule::query()->where('route_pattern_id', $pattern->id)->count())->toBe(1);
    });

    test('skips missing companies and environments that must not receive demo data', function (string $scenario): void {
        if ($scenario !== 'missing company') {
            createCompany(['slug' => 'smartbus-demo']);
            $this->app->instance('env', $scenario);
        }

        try {
            $command = $this->artisan('db:seed', [
                '--class' => SmartbusDemoRouteSeeder::class,
                '--force' => true,
            ]);
            if ($scenario === 'missing company') {
                $command->expectsOutputToContain('an active smartbus-demo company is required');
            }
            $command->assertSuccessful();
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertDatabaseCount('routes', 0);
        $this->assertDatabaseCount('route_patterns', 0);
        $this->assertDatabaseCount('route_schedules', 0);
    })->with(['missing company', 'production', 'staging', 'preview']);
});
