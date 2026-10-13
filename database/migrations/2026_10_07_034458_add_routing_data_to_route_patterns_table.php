<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_patterns', function (Blueprint $table): void {
            $table->jsonb('route_geometry')->nullable();
            $table->decimal('distance_meters', 12, 2)->nullable();
            $table->decimal('driving_duration_seconds', 12, 2)->nullable();
            $table->string('routing_points_hash', 64)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_patterns
            ADD CONSTRAINT route_patterns_routing_data_check
            CHECK (
                (
                    route_geometry IS NULL
                    AND distance_meters IS NULL
                    AND driving_duration_seconds IS NULL
                    AND routing_points_hash IS NULL
                )
                OR
                (
                    route_geometry IS NOT NULL
                    AND distance_meters IS NOT NULL
                    AND driving_duration_seconds IS NOT NULL
                    AND routing_points_hash IS NOT NULL
                    AND distance_meters >= 0
                    AND driving_duration_seconds >= 0
                    AND routing_points_hash ~ '^[a-f0-9]{64}$'
                    AND COALESCE(jsonb_typeof(route_geometry), '') = 'object'
                    AND COALESCE(route_geometry->>'type', '') = 'LineString'
                    AND CASE
                        WHEN jsonb_typeof(route_geometry->'coordinates') = 'array'
                        THEN jsonb_array_length(route_geometry->'coordinates') >= 2
                        ELSE FALSE
                    END
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE route_patterns DROP CONSTRAINT route_patterns_routing_data_check',
        );

        Schema::table('route_patterns', function (Blueprint $table): void {
            $table->dropColumn([
                'route_geometry',
                'distance_meters',
                'driving_duration_seconds',
                'routing_points_hash',
            ]);
        });
    }
};
