<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('gps_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('trip_id')
                ->constrained('trips')
                ->restrictOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();

            $table->timestampTz('recorded_at', 6);
            $table->timestampTz('received_at', 6)->useCurrent();

            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            
            $table->timestamps();

            $table->unique(['trip_id', 'recorded_at']);
            $table->index('recorded_at');
            $table->spatialIndex('location');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE gps_locations
                ADD CONSTRAINT gps_locations_coordinates_check CHECK (
                    latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180
                ),
                ADD CONSTRAINT gps_locations_speed_check CHECK (
                    speed_kmh IS NULL OR speed_kmh BETWEEN 0 AND 400
                ),
                ADD CONSTRAINT gps_locations_accuracy_check CHECK (
                    accuracy_meters IS NULL OR accuracy_meters >= 0
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_locations');
    }
};
