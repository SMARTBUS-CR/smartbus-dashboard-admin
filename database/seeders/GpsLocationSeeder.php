<?php

namespace Database\Seeders;

use App\Models\Trip;
use Illuminate\Database\Seeder;

class GpsLocationSeeder extends Seeder
{
    public function run(): void
    {
        $trip = Trip::query()->where('status', 'in_progress')->latest('id')->first();

        if (! $trip) {
            $this->command->warn('No se crearon posiciones GPS: no existe un viaje en progreso.');

            return;
        }

        $trip->gpsLocations()->createMany([
            [
                'latitude' => -0.1807,
                'longitude' => -78.4678,
                'speed_kmh' => 32.5,
                'recorded_at' => now()->subMinutes(10),
                'location' => 'POINT(-78.4678 -0.1807)',
            ],
            [
                'latitude' => -0.1769,
                'longitude' => -78.4791,
                'speed_kmh' => 28.2,
                'recorded_at' => now()->subMinutes(5),
                'location' => 'POINT(-78.4791 -0.1769)',
            ],
            [
                'latitude' => -0.1712,
                'longitude' => -78.4886,
                'speed_kmh' => 24.8,
                'recorded_at' => now(),
                'location' => 'POINT(-78.4886 -0.1712)',
            ],
        ]);
    }
}
