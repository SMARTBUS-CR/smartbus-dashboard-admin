<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui Routes Seeding', function (): void {
    test('creates the three routes and their supplied fares for the company', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        expect($company->country_code)->toBe('CR')
            ->and($company->timezone)->toBe('America/Costa_Rica');

        $expectedRoutes = [
            'PV-RF' => '1080.00',
            'PV-GUA' => '1570.00',
            'SJ-PV' => '3550.00',
        ];

        $routes = Route::query()
            ->where('company_id', $company->getKey())
            ->get()
            ->keyBy('code');

        expect($routes)->toHaveCount(3);

        foreach ($expectedRoutes as $code => $amount) {
            expect($routes->has($code))->toBeTrue();

            $fare = RouteFare::query()
                ->where('route_id', $routes->get($code)->getKey())
                ->sole();

            expect($fare->currency)->toBe('CRC')
                ->and($fare->amount)->toBe($amount);
        }
    });

    test('can run repeatedly without duplicating companies routes or fares', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $routeIds = Route::query()
            ->where('company_id', $company->getKey())
            ->orderBy('code')
            ->pluck('id')
            ->all();

        $fareIds = RouteFare::query()
            ->whereIn('route_id', $routeIds)
            ->orderBy('route_id')
            ->pluck('id')
            ->all();

        $patternIds = RoutePattern::query()
            ->whereIn('route_id', $routeIds)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $scheduleIds = RouteSchedule::query()
            ->whereIn('route_pattern_id', $patternIds)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        expect($patternIds)->toHaveCount(9)
            ->and($scheduleIds)->toHaveCount(490);

        $stopIds = Stop::query()
            ->where('company_id', $company->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $occurrences = RoutePatternStop::query()
            ->whereIn('route_pattern_id', $patternIds)
            ->orderBy('id')
            ->get([
                'id',
                'route_pattern_id',
                'stop_id',
                'stop_sequence',
                'minutes_from_start',
            ])
            ->toArray();

        expect($stopIds)->toHaveCount(7)
            ->and($occurrences)->toHaveCount(32);

        $this->seed(SarapiquiRoutesSeeder::class);

        expect(
            Company::withTrashed()
                ->where('slug', 'grupo-caribenos')
                ->count(),
        )->toBe(1)
            ->and(Route::withTrashed()
                ->where('company_id', $company->getKey())
                ->orderBy('code')
                ->pluck('id')->all())->toBe($routeIds)
            ->and(RouteFare::query()
                ->whereIn('route_id', $routeIds)
                ->orderBy('route_id')
                ->pluck('id')->all())->toBe($fareIds)
            ->and(RoutePattern::withTrashed()
                ->whereIn('route_id', $routeIds)
                ->orderBy('id')
                ->pluck('id')->all())->toBe($patternIds)
            ->and(RouteSchedule::query()
                ->whereIn('route_pattern_id', $patternIds)
                ->orderBy('id')
                ->pluck('id')->all())->toBe($scheduleIds)
            ->and(Stop::withTrashed()
                ->where('company_id', $company->getKey())
                ->orderBy('id')
                ->pluck('id')->all())->toBe($stopIds)
            ->and(RoutePatternStop::query()
                ->whereIn('route_pattern_id', $patternIds)
                ->orderBy('id')
                ->get([
                    'id',
                    'route_pattern_id',
                    'stop_id',
                    'stop_sequence',
                    'minutes_from_start',
                ])->toArray())->toBe($occurrences);
    });

    test('preserves a fare changed after the initial seed', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $route = Route::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'PV-RF')
            ->sole();

        $fare = RouteFare::query()
            ->where('route_id', $route->getKey())
            ->sole();

        $fare->update(['amount' => '1100.00']);

        $this->seed(SarapiquiRoutesSeeder::class);

        expect($fare->fresh()->amount)->toBe('1100.00')
            ->and(
                RouteFare::query()
                    ->where('route_id', $route->getKey())
                    ->count(),
            )->toBe(1);
    });
});
