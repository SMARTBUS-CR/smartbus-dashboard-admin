<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Trip;
use Illuminate\Database\Seeder;

class TripSeeder extends Seeder
{
    public function run(): void
    {
        $driver = Driver::query()->first();
        $route = Route::query()->first();
        $bus = Bus::query()->first();

        if (! $driver || ! $route || ! $bus) {
            $this->command->warn('No se crearon viajes: se requieren conductor, ruta y bus.');

            return;
        }

        Trip::factory()->create([
            'route_id' => $route->getKey(),
            'bus_id' => $bus->getKey(),
            'driver_id' => $driver->getKey(),
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(25),
        ]);

        Trip::factory()->completed()->create([
            'route_id' => $route->getKey(),
            'bus_id' => $bus->getKey(),
            'driver_id' => $driver->getKey(),
        ]);
    }
}
