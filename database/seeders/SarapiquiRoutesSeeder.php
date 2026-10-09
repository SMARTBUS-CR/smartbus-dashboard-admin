<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SarapiquiRoutesSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::withTrashed()->firstOrCreate(
            ['slug' => 'grupo-caribenos'],
            [
                'legal_name' => 'Corporación Terminales del Caribe CTC S.A.',
                'trade_name' => 'Grupo Caribeños',
                'country_code' => 'CR',
                'timezone' => 'America/Costa_Rica',
                'status' => CompanyStatus::ACTIVE,
            ],
        );

        if ($company->trashed()) {
            throw new RuntimeException(
                'Restore the Grupo Caribeños company before running this seeder.',
            );
        }

        $definitions = [
            [
                'code' => 'PV-RF',
                'name' => 'Puerto Viejo de Sarapiquí - Río Frío',
                'amount' => '1080.00',
            ],
            [
                'code' => 'PV-GUA',
                'name' => 'Puerto Viejo de Sarapiquí - Guápiles',
                'amount' => '1570.00',
            ],
            [
                'code' => 'SJ-PV',
                'name' => 'San José - Puerto Viejo de Sarapiquí',
                'amount' => '3550.00',
            ],
        ];

        DB::connection('pgsql')->transaction(
            function () use ($company, $definitions): void {
                $this->seedStops($company);

                foreach ($definitions as $definition) {
                    $route = Route::withTrashed()->firstOrCreate(
                        [
                            'company_id' => $company->getKey(),
                            'code' => $definition['code'],
                        ],
                        [
                            'name' => $definition['name'],
                        ],
                    );

                    if ($route->trashed()) {
                        throw new RuntimeException(
                            "Restore route {$definition['code']} before running this seeder.",
                        );
                    }

                    RouteFare::firstOrCreate(
                        [
                            'route_id' => $route->getKey(),
                            'currency' => 'CRC',
                            'valid_from' => null,
                            'valid_until' => null,
                        ],
                        [
                            'amount' => $definition['amount'],
                        ],
                    );

                    $this->seedPatterns($route);
                }
            },
        );
    }

    private function seedPatterns(Route $route): void
    {
        $patterns = match ($route->code) {
            'PV-RF' => [
                'OUTBOUND' => [
                    'Puerto Viejo de Sarapiquí - Río Frío',
                    'Río Frío',
                ],
                'INBOUND' => [
                    'Río Frío - Puerto Viejo de Sarapiquí',
                    'Puerto Viejo de Sarapiquí',
                ],
            ],

            'PV-GUA' => [
                'OUTBOUND' => [
                    'Puerto Viejo de Sarapiquí - Guápiles',
                    'Guápiles',
                ],
                'INBOUND' => [
                    'Guápiles - Puerto Viejo de Sarapiquí',
                    'Puerto Viejo de Sarapiquí',
                ],
            ],

            'SJ-PV' => [
                'OUTBOUND-VARA-BLANCA' => [
                    'San José - Puerto Viejo de Sarapiquí por Vara Blanca',
                    'Puerto Viejo de Sarapiquí',
                ],
                'OUTBOUND-ZURQUI' => [
                    'San José - Puerto Viejo de Sarapiquí por Zurquí y Río Frío',
                    'Puerto Viejo de Sarapiquí',
                ],
                'INBOUND-VARA-BLANCA' => [
                    'Puerto Viejo de Sarapiquí - San José por Vara Blanca',
                    'San José',
                ],
                'INBOUND-ZURQUI-DIRECT' => [
                    'Puerto Viejo de Sarapiquí - San José por Zurquí sin Río Frío',
                    'San José',
                ],
                'INBOUND-ZURQUI-RIO-FRIO' => [
                    'Puerto Viejo de Sarapiquí - San José por Río Frío y Zurquí',
                    'San José',
                ],
            ],

            default => throw new RuntimeException(
                "Unsupported seeded route code: {$route->code}",
            ),
        };

        foreach ($patterns as $code => [$name, $destination]) {
            $pattern = RoutePattern::withTrashed()->firstOrCreate(
                [
                    'route_id' => $route->getKey(),
                    'code' => $code,
                ],
                [
                    'name' => $name,
                    'headsign' => $destination,
                ],
            );

            if ($pattern->trashed()) {
                throw new RuntimeException(
                    "Restore pattern {$route->code}/{$code} before running this seeder.",
                );
            }

            $createdOccurrences = $this->seedStopOccurrences($route, $pattern);

            if ($createdOccurrences) {
                $this->seedRoutingAdjustments($route, $pattern);
            }

            $this->seedSchedules($route, $pattern);
        }
    }

    private function seedSchedules(
        Route $route,
        RoutePattern $pattern,
    ): void {
        $key = $route->code.'/'.$pattern->code;

        $departures = match ($key) {
            'PV-RF/OUTBOUND', 'PV-RF/INBOUND' => array_map(
                static fn (int $hour): string => sprintf('%02d:00', $hour),
                range(6, 18),
            ),

            'PV-GUA/OUTBOUND' => [
                '05:30', '06:45', '07:10', '08:40',
                '09:40', '10:30', '12:10', '13:15',
                '14:30', '15:45', '17:00', '19:10',
            ],

            'PV-GUA/INBOUND' => [
                '05:30', '07:00', '08:00', '09:00',
                '10:30', '12:00', '13:15', '14:30',
                '16:00', '17:00', '18:30',
            ],

            'SJ-PV/OUTBOUND-VARA-BLANCA' => [
                '06:30', '13:00', '17:00',
            ],

            'SJ-PV/OUTBOUND-ZURQUI' => [
                '06:30', '10:00', '11:30', '13:30',
                '14:30', '15:30', '17:15', '18:00',
            ],

            'SJ-PV/INBOUND-VARA-BLANCA' => [
                '05:00', '11:30', '16:30',
            ],

            'SJ-PV/INBOUND-ZURQUI-DIRECT' => [
                '05:30', '07:30', '09:30',
            ],

            'SJ-PV/INBOUND-ZURQUI-RIO-FRIO' => [
                '11:00', '13:30', '15:00', '17:30',
            ],

            default => [],
        };

        foreach (range(0, 6) as $day) {
            $dailyDepartures = $departures;

            if ($key === 'PV-GUA/INBOUND' && $day === 0) {
                $dailyDepartures[0] = '06:00';
            }

            if ($key === 'SJ-PV/INBOUND-ZURQUI-DIRECT' && $day === 0) {
                $dailyDepartures = [
                    ...$dailyDepartures,
                    '11:00',
                    '13:30',
                    '15:00',
                    '17:30',
                ];
            }

            if ($key === 'SJ-PV/INBOUND-ZURQUI-RIO-FRIO' && $day === 0) {
                $dailyDepartures = [];
            }

            foreach ($dailyDepartures as $departure) {
                RouteSchedule::firstOrCreate([
                    'route_pattern_id' => $pattern->getKey(),
                    'day_of_week' => $day,
                    'departure_time' => $departure.':00',
                    'valid_from' => null,
                    'valid_until' => null,
                ]);
            }
        }
    }

    private function seedStops(Company $company): void
    {
        $terminals = [
            [
                'name' => 'Terminal de Buses Sarapiquí',
                'description' => 'Calle Central, Puerto Viejo, Heredia, Costa Rica.',
                'latitude' => '10.4547665',
                'longitude' => '-84.0091243',
            ],
            [
                'name' => 'Terminal de Buses de Río Frío',
                'description' => 'Vía 229, Las Horquetas, Heredia, Costa Rica.',
                'latitude' => '10.3202270',
                'longitude' => '-83.8879793',
            ],
            [
                'name' => 'Terminal de Buses Guápiles',
                'description' => 'Avenida 8, Guápiles, Limón, Costa Rica.',
                'latitude' => '10.2105557',
                'longitude' => '-83.7900787',
            ],
            [
                'name' => 'Gran Terminal del Caribe',
                'description' => 'Calle Central Alfredo Volio, San José, Costa Rica.',
                'latitude' => '9.9406200',
                'longitude' => '-84.0791062',
            ],
            [
                'name' => 'Terminal Cruce Río Frío',
                'description' => 'Carretera Braulio Carrillo, Guápiles, Limón, Costa Rica.',
                'latitude' => '10.2112711',
                'longitude' => '-83.8985962',
            ],
            [
                'name' => 'Las Horquetas',
                'description' => 'Las Horquetas, Heredia, Costa Rica.',
                'latitude' => '10.3363646',
                'longitude' => '-83.9541154',
            ],
            [
                'name' => 'Bar Restaurante Quique',
                'description' => 'Calle El Bambú, Las Horquetas, Heredia, Costa Rica.',
                'latitude' => '10.3375040',
                'longitude' => '-83.9520581',
            ],
        ];

        foreach ($terminals as $terminal) {
            $stop = Stop::withTrashed()->firstOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'name' => $terminal['name'],
                ],
                [
                    'description' => $terminal['description'],
                    'latitude' => $terminal['latitude'],
                    'longitude' => $terminal['longitude'],
                ],
            );

            if ($stop->trashed()) {
                throw new RuntimeException(
                    "Restore stop {$terminal['name']} before running this seeder.",
                );
            }
        }
    }

    private function seedStopOccurrences(
        Route $route,
        RoutePattern $pattern,
    ): bool {
        if ($pattern->stopOccurrences()->exists()) {
            return false;
        }

        $puertoViejo = 'Terminal de Buses Sarapiquí';
        $rioFrio = 'Terminal de Buses de Río Frío';
        $guapiles = 'Terminal de Buses Guápiles';
        $sanJose = 'Gran Terminal del Caribe';
        $cruce = 'Terminal Cruce Río Frío';
        $horquetas = 'Las Horquetas';
        $quique = 'Bar Restaurante Quique';

        $definitions = match ($route->code.'/'.$pattern->code) {
            'PV-RF/OUTBOUND' => [
                [$puertoViejo, 0],
                [$horquetas, 30],
                [$rioFrio, 60],
            ],
            'PV-RF/INBOUND' => [
                [$rioFrio, 0],
                [$horquetas, 30],
                [$puertoViejo, 60],
            ],
            'PV-GUA/OUTBOUND' => [
                [$puertoViejo, 0],
                [$quique, 30],
                [$cruce, 60],
                [$guapiles, null],
            ],
            'PV-GUA/INBOUND' => [
                [$guapiles, 0],
                [$cruce, 40],
                [$quique, 45],
                [$puertoViejo, null],
            ],
            'SJ-PV/OUTBOUND-VARA-BLANCA' => [
                [$sanJose, 0],
                [$puertoViejo, null],
            ],
            'SJ-PV/OUTBOUND-ZURQUI' => [
                [$sanJose, 0],
                [$cruce, 90],
                [$rioFrio, null],
                [$quique, 120],
                [$puertoViejo, null],
            ],
            'SJ-PV/INBOUND-VARA-BLANCA' => [
                [$puertoViejo, 0],
                [$sanJose, null],
            ],
            'SJ-PV/INBOUND-ZURQUI-DIRECT' => [
                [$puertoViejo, 0],
                [$quique, 30],
                [$cruce, 60],
                [$sanJose, null],
            ],
            'SJ-PV/INBOUND-ZURQUI-RIO-FRIO' => [
                [$puertoViejo, 0],
                [$quique, 30],
                [$rioFrio, null],
                [$cruce, 60],
                [$sanJose, null],
            ],
            default => throw new RuntimeException(
                "Unsupported seeded pattern: {$route->code}/{$pattern->code}",
            ),
        };

        foreach ($definitions as $index => [$name, $minutes]) {
            $stop = Stop::query()
                ->where('company_id', $route->company_id)
                ->where('name', $name)
                ->sole();

            $pattern->stopOccurrences()->create([
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $minutes,
            ]);
        }

        return true;
    }

    private function seedRoutingAdjustments(
        Route $route,
        RoutePattern $pattern,
    ): void {
        if ($route->code !== 'PV-RF') {
            return;
        }

        $entrance = [
            'lat' => 10.3514474,
            'lng' => -83.9587524,
        ];

        $exit = [
            'lat' => 10.3326604,
            'lng' => -83.9500889,
        ];

        $points = match ($pattern->code) {
            'OUTBOUND' => [$entrance, $exit],
            'INBOUND' => [$exit, $entrance],
            default => throw new RuntimeException(
                "Unsupported Calle 0 pattern: {$pattern->code}",
            ),
        };

        $occurrences = $pattern->stopOccurrences()
            ->orderBy('stop_sequence')
            ->get();

        if ($occurrences->count() !== 3) {
            throw new RuntimeException(
                'Calle 0 routing adjustments require exactly three stop occurrences.',
            );
        }

        $pattern->update([
            'routing_adjustments' => [
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
            ],
        ]);
    }
}
