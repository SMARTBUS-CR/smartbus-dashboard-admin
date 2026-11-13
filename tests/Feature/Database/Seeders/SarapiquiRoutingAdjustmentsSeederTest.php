<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use Database\Seeders\SarapiquiRoutesSeeder;
use Illuminate\Support\Facades\Http;

describe('Sarapiqui Routing Adjustments Seeding', function (): void {
    test('guides both directions through Calle 0 without contacting external providers', function (): void {
        Http::fake();
        Http::preventStrayRequests();

        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $route = Route::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'PV-RF')
            ->sole();

        $entrance = [
            'lat' => 10.3514474,
            'lng' => -83.9587524,
        ];

        $exit = [
            'lat' => 10.3326604,
            'lng' => -83.9500889,
        ];

        $pointsByDirection = [
            'OUTBOUND' => [$entrance, $exit],
            'INBOUND' => [$exit, $entrance],
        ];

        foreach ($pointsByDirection as $code => $points) {
            $pattern = RoutePattern::query()
                ->where('route_id', $route->getKey())
                ->where('code', $code)
                ->sole();

            $occurrences = $pattern->stopOccurrences()
                ->orderBy('stop_sequence')
                ->get();

            expect($occurrences)->toHaveCount(3)
                ->and($pattern->routing_adjustments)->toEqual([
                    [
                        'from_occurrence_id' => $occurrences[0]->getKey(),
                        'to_occurrence_id' => $occurrences[1]->getKey(),
                        'points' => [$points[0]],
                    ],
                    [
                        'from_occurrence_id' => $occurrences[1]->getKey(),
                        'to_occurrence_id' => $occurrences[2]->getKey(),
                        'points' => [$points[1]],
                    ],
                ])
                ->and($pattern->route_geometry)->toBeNull()
                ->and($pattern->distance_meters)->toBeNull()
                ->and($pattern->driving_duration_seconds)->toBeNull()
                ->and($pattern->routing_points_hash)->toBeNull();
        }

        Http::assertNothingSent();
    });

    test('preserves routing adjustments changed after the initial seed', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $route = Route::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'PV-RF')
            ->sole();

        $pattern = RoutePattern::query()
            ->where('route_id', $route->getKey())
            ->where('code', 'OUTBOUND')
            ->sole();

        $occurrences = $pattern->stopOccurrences()
            ->orderBy('stop_sequence')
            ->get();

        $adjustments = [
            [
                'from_occurrence_id' => $occurrences[0]->getKey(),
                'to_occurrence_id' => $occurrences[1]->getKey(),
                'points' => [
                    ['lat' => 10.3500001, 'lng' => -83.9580001],
                ],
            ],
        ];

        $pattern->update([
            'routing_adjustments' => $adjustments,
        ]);

        $this->seed(SarapiquiRoutesSeeder::class);

        expect($pattern->fresh()->routing_adjustments)
            ->toEqual($adjustments);
    });
});
