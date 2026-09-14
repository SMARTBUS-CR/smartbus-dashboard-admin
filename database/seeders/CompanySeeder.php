<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use DB;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company1 = Company::firstOrCreate(
            ['slug' => 'smartbus-demo'],
            [
                'name' => 'Transportes SmartBus Demo',
                'legal_id' => '1790012345001',
                'phone' => '+593 99 999 9999',
                'email' => 'operaciones@smartbus.com',
                'address' => 'Av. Principal y Terminal Terrestre',
            ]
        );

        $company2 = Company::firstOrCreate(
            ['slug' => 'transporte-urbano'],
            [
                'name' => 'Transporte Urbano S.A.',
                'legal_id' => '1790012345002',
                'phone' => '+593 98 888 8888',
                'email' => 'contacto@transporteurbano.com',
                'address' => 'Calle 123 y Av. Principal',
            ]
        );

        $user = User::first();

        if ($user) {
            $company1->attachUser($user);
            $company2->attachUser($user);

            $this->command->info("Empresas vinculadas exitosamente al usuario: {$user->email}");
        }

        $company1->buses()->firstOrCreate(
            ['plate_number' => 'ABC-1234'],
            [
                'unit_number' => 'U-01',
                'brand' => 'Mercedes-Benz',
                'model' => 'O500',
                'year' => 2024,
                'capacity' => 45,
                'is_active' => true,
            ]
        );

        $company1->routes()->firstOrCreate(
            ['code' => 'R-101'],
            [
                'name' => 'Línea 1 - Norte / Terminal',
                'origin_lat' => -0.180653,
                'origin_lng' => -78.467838,
                'destination_lat' => -0.229850,
                'destination_lng' => -78.513270,
                'overview_polyline' => 'v`|fD_~{uO...dummy_polyline_string...',

                // Create the LINESTRING in WKT format for PostGIS (Longitude Latitude)
                'path' => DB::raw("ST_GeomFromText('LINESTRING(-78.467838 -0.180653, -78.475 -0.19, -78.48 -0.2, -78.485 -0.21, -78.51327 -0.22985)', 4326)"),

                // Waypoints stored as an array of coordinates for Filament Leaflet
                'waypoints' => [
                    ['lat' => -0.180653, 'lng' => -78.467838],
                    ['lat' => -0.190000, 'lng' => -78.475000],
                    ['lat' => -0.200000, 'lng' => -78.480000],
                    ['lat' => -0.210000, 'lng' => -78.485000],
                    ['lat' => -0.229850, 'lng' => -78.513270],
                ],
                'is_active' => true,
            ]
        );

        // $company1->routes()->firstOrCreate(
        //     ['code' => 'R-202'],
        //     [
        //         'name' => 'Línea 2 - Centro / Sur',
        //         'origin_lat' => -0.229850,
        //         'origin_lng' => -78.513270,
        //         'destination_lat' => -0.300000,
        //         'destination_lng' => -78.550000,
        //         'overview_polyline' => 'a~|fD_~{uO...dummy_polyline_string...',
        //         'path' => [
        //             ['lat' => -0.229850, 'lng' => -78.513270],
        //             ['lat' => -0.300000, 'lng' => -78.550000],
        //         ],
        //         'waypoints' => [
        //             ['lat' => -0.229850, 'lng' => -78.513270],
        //             ['lat' => -0.300000, 'lng' => -78.550000],
        //         ],
        //         'is_active' => true,
        //     ]
        // );
    }
}
