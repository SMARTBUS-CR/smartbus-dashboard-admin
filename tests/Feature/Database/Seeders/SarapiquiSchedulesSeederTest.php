<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui Schedule Seeding', function (): void {
    $hourlyDepartures = [
        '06:00', '07:00', '08:00', '09:00', '10:00',
        '11:00', '12:00', '13:00', '14:00', '15:00',
        '16:00', '17:00', '18:00',
    ];

    test('creates the exact weekly departures for each direction', function (string $routeCode, string $patternCode, array $departures, ?string $sundayFirstDeparture): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $route = Route::query()
            ->where('company_id', $company->getKey())
            ->where('code', $routeCode)
            ->sole();

        $pattern = RoutePattern::query()
            ->where('route_id', $route->getKey())
            ->where('code', $patternCode)
            ->sole();

        foreach (range(0, 6) as $day) {
            $expected = $departures;

            if ($day === 0 && $sundayFirstDeparture !== null) {
                $expected[0] = $sundayFirstDeparture;
            }

            sort($expected);

            $actual = RouteSchedule::query()
                ->where('route_pattern_id', $pattern->getKey())
                ->where('day_of_week', $day)
                ->orderBy('departure_time')
                ->pluck('departure_time')
                ->map(
                    fn (string $time): string => substr($time, 0, 5),
                )
                ->all();

            expect($actual)->toBe($expected);
        }
    })->with([
        'Puerto Viejo to Río Frío' => [
            'PV-RF',
            'OUTBOUND',
            $hourlyDepartures,
            null,
        ],
        'Río Frío to Puerto Viejo' => [
            'PV-RF',
            'INBOUND',
            $hourlyDepartures,
            null,
        ],
        'Puerto Viejo to Guápiles' => [
            'PV-GUA',
            'OUTBOUND',
            [
                '05:30', '06:45', '07:10', '08:40',
                '09:40', '10:30', '12:10', '13:15',
                '14:30', '15:45', '17:00', '19:10',
            ],
            null,
        ],
        'Guápiles to Puerto Viejo' => [
            'PV-GUA',
            'INBOUND',
            [
                '05:30', '07:00', '08:00', '09:00',
                '10:30', '12:00', '13:15', '14:30',
                '16:00', '17:00', '18:30',
            ],
            '06:00',
        ],
    ]);
});
