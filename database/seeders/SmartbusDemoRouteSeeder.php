<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SmartbusDemoRouteSeeder extends Seeder
{
    private const APPROVED_ENVIRONMENTS = ['local', 'development', 'testing'];

    public function run(): void
    {
        if (! app()->environment(self::APPROVED_ENVIRONMENTS)) {
            $this->command?->warn('SmartbusDemoRouteSeeder skipped: only local, development and testing environments are approved.');

            return;
        }

        $company = Company::query()->where('slug', 'smartbus-demo')
            ->where('status', CompanyStatus::ACTIVE)->first();

        if ($company === null) {
            $this->command?->warn('SmartbusDemoRouteSeeder skipped: an active smartbus-demo company is required.');

            return;
        }

        DB::connection('pgsql')->transaction(function () use ($company): void {
            $route = Route::withTrashed()->firstOrCreate(
                ['company_id' => $company->getKey(), 'code' => 'DEMO-CITY'],
                ['name' => 'SmartBus Demo City Route'],
            );
            $this->requireAvailable($route, 'Restore the DEMO-CITY route before running SmartbusDemoRouteSeeder.');

            $pattern = RoutePattern::withTrashed()->firstOrCreate(
                ['route_id' => $route->getKey(), 'code' => 'OUTBOUND'],
                ['name' => 'SmartBus Demo Outbound', 'headsign' => 'SmartBus Demo Destination'],
            );
            $this->requireAvailable($pattern, 'Restore the DEMO-CITY/OUTBOUND pattern before running SmartbusDemoRouteSeeder.');

            $stops = collect([
                [
                    'name' => 'SmartBus Demo Terminal',
                    'description' => 'Fictional origin used only for development fixtures.',
                    'latitude' => '9.9281000',
                    'longitude' => '-84.0907000',
                    'minutes_from_start' => 0,
                ],
                [
                    'name' => 'SmartBus Demo Destination',
                    'description' => 'Fictional destination used only for development fixtures.',
                    'latitude' => '9.9355000',
                    'longitude' => '-84.0786000',
                    'minutes_from_start' => 15,
                ],
            ])->map(function (array $definition) use ($company): array {
                $stop = Stop::withTrashed()->firstOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $definition['name']],
                    [
                        'description' => $definition['description'],
                        'latitude' => $definition['latitude'],
                        'longitude' => $definition['longitude'],
                    ],
                );
                $this->requireAvailable($stop, "Restore the {$definition['name']} stop before running SmartbusDemoRouteSeeder.");

                return ['stop' => $stop, 'minutes_from_start' => $definition['minutes_from_start']];
            });

            foreach ($stops as $index => $definition) {
                $occurrence = RoutePatternStop::query()->firstOrCreate(
                    ['route_pattern_id' => $pattern->getKey(), 'stop_sequence' => $index + 1],
                    ['stop_id' => $definition['stop']->getKey(), 'minutes_from_start' => $definition['minutes_from_start']],
                );

                if ((string) $occurrence->stop_id !== (string) $definition['stop']->getKey()) {
                    throw new RuntimeException('The DEMO-CITY/OUTBOUND stop sequence is already owned by another stop.');
                }
            }

            $pattern->refresh();

            if ($pattern->routing_points_hash === null) {
                $points = $stops->map(fn (array $definition): array => [
                    'lat' => (float) $definition['stop']->latitude,
                    'lng' => (float) $definition['stop']->longitude,
                ])->all();
                $coordinates = implode(';', array_map(
                    fn (array $point): string => sprintf('%.7F,%.7F', $point['lng'], $point['lat']),
                    $points,
                ));

                $pattern->update([
                    'route_geometry' => [
                        'type' => 'LineString',
                        'coordinates' => array_map(fn (array $point): array => [$point['lng'], $point['lat']], $points),
                    ],
                    'distance_meters' => '1800.00',
                    'driving_duration_seconds' => '900.00',
                    'routing_points_hash' => hash('sha256', $coordinates),
                    'routing_leg_distances' => [1800],
                ]);
            }

            RouteSchedule::query()->firstOrCreate([
                'route_pattern_id' => $pattern->getKey(),
                'day_of_week' => 1,
                'departure_time' => '09:00:00',
                'valid_from' => null,
                'valid_until' => null,
            ]);
        });
    }

    private function requireAvailable(Route|RoutePattern|Stop $record, string $message): void
    {
        if ($record->trashed()) {
            throw new RuntimeException($message);
        }
    }
}
