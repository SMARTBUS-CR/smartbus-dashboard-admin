<?php

use App\Models\RouteSchedule;
use Database\Seeders\SarapiquiRoutesSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('seeds weekly schedules within a bounded database query budget', function (): void {
    $scheduleQueries = 0;
    $tracking = true;

    DB::listen(function (QueryExecuted $query) use (&$scheduleQueries, &$tracking): void {
        if (
            ! $tracking
            || $query->connectionName !== 'pgsql'
            || ! str_contains(strtolower($query->sql), 'route_schedules')
        ) {
            return;
        }

        $scheduleQueries++;

        if ($scheduleQueries > 30) {
            throw new RuntimeException(
                'Schedule seeding exceeded the database query budget.',
            );
        }
    });

    $this->seed(SarapiquiRoutesSeeder::class);

    $tracking = false;

    expect($scheduleQueries)->toBeLessThanOrEqual(30)
        ->and(RouteSchedule::count())->toBe(490);
});
