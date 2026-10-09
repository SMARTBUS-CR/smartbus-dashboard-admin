<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui San Jose Schedule Seeding', function (): void {
    test('assigns departures to the correct journey variant and weekday', function (string $patternCode, array $weekdayDepartures, array $sundayDepartures): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $route = Route::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'SJ-PV')
            ->sole();

        $pattern = RoutePattern::query()
            ->where('route_id', $route->getKey())
            ->where('code', $patternCode)
            ->sole();

        foreach (range(0, 6) as $day) {
            $expected = $day === 0
                ? $sundayDepartures
                : $weekdayDepartures;

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
        'San José to Puerto Viejo via Vara Blanca' => [
            'OUTBOUND-VARA-BLANCA',
            ['06:30', '13:00', '17:00'],
            ['06:30', '13:00', '17:00'],
        ],
        'San José to Puerto Viejo via Zurquí and Río Frío' => [
            'OUTBOUND-ZURQUI',
            [
                '06:30', '10:00', '11:30', '13:30',
                '14:30', '15:30', '17:15', '18:00',
            ],
            [
                '06:30', '10:00', '11:30', '13:30',
                '14:30', '15:30', '17:15', '18:00',
            ],
        ],
        'Puerto Viejo to San José via Vara Blanca' => [
            'INBOUND-VARA-BLANCA',
            ['05:00', '11:30', '16:30'],
            ['05:00', '11:30', '16:30'],
        ],
        'Puerto Viejo to San José via Zurquí without Río Frío' => [
            'INBOUND-ZURQUI-DIRECT',
            ['05:30', '07:30', '09:30'],
            [
                '05:30', '07:30', '09:30', '11:00',
                '13:30', '15:00', '17:30',
            ],
        ],
        'Puerto Viejo to San José via Río Frío and Zurquí' => [
            'INBOUND-ZURQUI-RIO-FRIO',
            ['11:00', '13:30', '15:00', '17:30'],
            [],
        ],
    ]);
});
