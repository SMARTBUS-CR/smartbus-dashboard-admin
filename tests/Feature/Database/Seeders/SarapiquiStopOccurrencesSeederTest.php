<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui Stop Occurrences Seeding', function (): void {
    test('assigns ordered stops and estimated times to each journey variant', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        $puertoViejo = 'Terminal de Buses Sarapiquí';
        $rioFrio = 'Terminal de Buses de Río Frío';
        $guapiles = 'Terminal de Buses Guápiles';
        $sanJose = 'Gran Terminal del Caribe';
        $cruce = 'Terminal Cruce Río Frío';
        $horquetas = 'Las Horquetas';
        $quique = 'Bar Restaurante Quique';

        $expected = [
            'PV-RF' => [
                'OUTBOUND' => [
                    [$puertoViejo, 0],
                    [$horquetas, 30],
                    [$rioFrio, 60],
                ],
                'INBOUND' => [
                    [$rioFrio, 0],
                    [$horquetas, 30],
                    [$puertoViejo, 60],
                ],
            ],
            'PV-GUA' => [
                'OUTBOUND' => [
                    [$puertoViejo, 0],
                    [$quique, 30],
                    [$cruce, 60],
                    [$guapiles, null],
                ],
                'INBOUND' => [
                    [$guapiles, 0],
                    [$cruce, 40],
                    [$quique, 45],
                    [$puertoViejo, null],
                ],
            ],
            'SJ-PV' => [
                'OUTBOUND-VARA-BLANCA' => [
                    [$sanJose, 0],
                    [$puertoViejo, null],
                ],
                'OUTBOUND-ZURQUI' => [
                    [$sanJose, 0],
                    [$cruce, 90],
                    [$rioFrio, null],
                    [$quique, 120],
                    [$puertoViejo, null],
                ],
                'INBOUND-VARA-BLANCA' => [
                    [$puertoViejo, 0],
                    [$sanJose, null],
                ],
                'INBOUND-ZURQUI-DIRECT' => [
                    [$puertoViejo, 0],
                    [$quique, 30],
                    [$cruce, 60],
                    [$sanJose, null],
                ],
                'INBOUND-ZURQUI-RIO-FRIO' => [
                    [$puertoViejo, 0],
                    [$quique, 30],
                    [$rioFrio, null],
                    [$cruce, 60],
                    [$sanJose, null],
                ],
            ],
        ];

        foreach ($expected as $routeCode => $patterns) {
            $route = Route::query()
                ->where('company_id', $company->getKey())
                ->where('code', $routeCode)
                ->sole();

            foreach ($patterns as $patternCode => $expectedStops) {
                $pattern = RoutePattern::query()
                    ->where('route_id', $route->getKey())
                    ->where('code', $patternCode)
                    ->sole();

                $occurrences = $pattern->stopOccurrences()
                    ->with('stop')
                    ->orderBy('stop_sequence')
                    ->get();

                expect($occurrences->pluck('stop_sequence')->all())
                    ->toBe(range(1, count($expectedStops)));

                $actual = $occurrences
                    ->map(fn (RoutePatternStop $occurrence): array => [
                        $occurrence->stop?->name,
                        $occurrence->minutes_from_start,
                    ])
                    ->all();

                expect($actual)->toBe($expectedStops);
            }
        }
    });
});
