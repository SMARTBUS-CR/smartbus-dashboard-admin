<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(
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

        $user = User::first();

        if ($user) {
            $company->attachUser($user);

            $this->command->info("Empresa vinculada exitosamente al usuario: {$user->email}");
        }

        $company->buses()->firstOrCreate(
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

        $company->routes()->firstOrCreate(
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
