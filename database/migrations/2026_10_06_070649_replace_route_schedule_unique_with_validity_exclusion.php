<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::table('route_schedules', function (Blueprint $table): void {
            $table->dropUnique([
                'route_pattern_id',
                'day_of_week',
                'departure_time',
            ]);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                ADD CONSTRAINT route_schedules_departure_validity_exclusion
                    EXCLUDE USING gist (
                        route_pattern_id WITH =,
                        day_of_week WITH =,
                        departure_time WITH =,
                        daterange(valid_from, valid_until, '[]') WITH &&
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::table('route_schedules', function (Blueprint $table): void {
            $table->unique([
                'route_pattern_id',
                'day_of_week',
                'departure_time',
            ]);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                DROP CONSTRAINT route_schedules_departure_validity_exclusion
            SQL);
    }
};
