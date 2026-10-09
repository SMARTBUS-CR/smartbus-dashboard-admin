<?php

use App\Models\Company;
use App\Models\Stop;
use Database\Seeders\SarapiquiRoutesSeeder;

describe('Sarapiqui Stop Seeding', function (): void {
    test('creates the four supplied terminals with their exact coordinates', function (): void {
        $this->seed(SarapiquiRoutesSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

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

        expect(
            Stop::query()
                ->where('company_id', $company->getKey())
                ->count(),
        )->toBe(7);

        foreach ($terminals as $terminal) {
            $this->assertDatabaseHas(Stop::class, [
                'company_id' => $company->getKey(),
                ...$terminal,
                'deleted_at' => null,
            ]);
        }
    });
});
