<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
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
                'is_active' => true,
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
                'is_active' => true,
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
                'origin' => 'Estación Norte',
                'destination' => 'Terminal Central',
                'distance_km' => 18.5,
                'estimated_duration_minutes' => 40,
                'is_active' => true,
            ]
        );
    }
}
